<?php

namespace DigitalStars\SimpleVK\EventDispatcher;
use DigitalStars\SimpleVK\Psr\SimpleCache\CacheInterface;

use ReflectionFunctionAbstract;
class ArgumentResolver
{
    private array $metadataCache = [];
    public function __construct(private readonly ?CacheInterface $persistentCache = null)
    {
    }

    /**
     * Получает метаданные о параметрах метода, используя кэш.
     */
    private function getMethodParameters(ReflectionFunctionAbstract $method): array
    {
        // 1. Создаем уникальный ключ для кэша.
        $className = $method->getDeclaringClass()?->getName() ?? '';
        $cacheKey = $className . '::' . $method->getName();

        // 2. Проверяем кэш.
        if (isset($this->metadataCache[$cacheKey])) {
            return $this->metadataCache[$cacheKey];
        }

        if ($this->persistentCache && $this->persistentCache->has($cacheKey)) {
            $paramsData = $this->persistentCache->get($cacheKey);
            $this->metadataCache[$cacheKey] = $paramsData;
            return $paramsData;
        }

        // 3. Если в кэше нет - анализируем и сохраняем.
        $paramsData = [];
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            $paramsData[] = [
                'name' => $param->getName(),
                'type_name' => $type && !$type->isBuiltin() ? $type->getName() : null,
                'allows_null' => $param->allowsNull(),
                'is_default_available' => $param->isDefaultValueAvailable(),
                'default_value' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
            ];
        }

        $this->metadataCache[$cacheKey] = $paramsData;
        $this->persistentCache?->set($cacheKey, $paramsData);

        return $paramsData;
    }


    /**
     * Собирает массив аргументов для вызова метода.
     *
     * @param ReflectionFunctionAbstract $reflectionMethod Рефлексия метода (или функции).
     * @param Context $context Контекст текущего события.
     * @param array $availableArgs Ассоциативный/числовой массив доступных аргументов (из payload/regex).
     * @return array Готовый массив аргументов для вызова.
     * @throws \Exception
     */
    public function getArguments(\ReflectionFunctionAbstract $reflectionMethod, Context $context, array $availableArgs = []): array
    {
        $finalArgs = [];

        $methodParams = $this->getMethodParameters($reflectionMethod);

        foreach ($methodParams as $param) {
            $paramName = $param['name'];
            $paramTypeName = $param['type_name'];

            // ПРИОРИТЕТ 1: Контекст выполнения
            if ($paramName === Context::class) {
                $finalArgs[] = $context;
                continue;
            }

            // ПРИОРИТЕТ 2: Явно переданные именованные аргументы
            if (array_key_exists($paramName, $availableArgs)) {
                $finalArgs[] = $availableArgs[$paramName];
                unset($availableArgs[$paramName]); // Удаляем, чтобы не использовать повторно
                continue;
            }

            // ПРИОРИТЕТ 3: Внедрение зависимостей через фабрику (с DI или без)
            if ($paramTypeName) {
                try {
                    $finalArgs[] = $context->get($paramTypeName);
                    continue;
                } catch (\Exception $e) {
                    // Возможно нет фабрики, возможно допустимо значение по умолчанию
                    // Возможно фабрика не смогла разрешить зависимость, пробуем другие варианты
                }
            }

            // ПРИОРИТЕТ 4: Аргументы по порядку (из regex)
            if (isset($availableArgs[0])) {
                $finalArgs[] = array_shift($availableArgs);
                continue;
            }

            // ПРИОРИТЕТ 5: Значение по умолчанию
            if ($param['is_default_available']) {
                $finalArgs[] = $param['default_value'];
                continue;
            }

            // ПРИОРИТЕТ 6: Nullable-параметр
            if ($param['allows_null']) {
                $finalArgs[] = null;
                continue;
            }

            $controllerName = $reflectionMethod->getDeclaringClass()?->getName() . '::' . $reflectionMethod->getName() . '()';
            throw new \RuntimeException("Не удалось определить значение для параметра '{$paramName}' в методе '{$controllerName}'.");
        }

        return $finalArgs;
    }
}