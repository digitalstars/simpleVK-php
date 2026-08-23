<?php

namespace DigitalStars\SimpleVK\V4\Dispatcher;

use Psr\SimpleCache\CacheInterface;
use ReflectionFunctionAbstract;

/**
 * Резолвер аргументов методов Action/View: контекст, payload/regex-аргументы,
 * DI через фабрику, значения по умолчанию.
 */
class ArgumentResolver
{
    /** @var array<string, array> */
    private array $metadataCache = [];

    public function __construct(
        private readonly ?CacheInterface $persistentCache = null,
    ) {}

    /**
     * Получает метаданные о параметрах метода, используя кэш.
     *
     * @return array<int, array{name: string, type_name: ?string, allows_null: bool, is_default_available: bool, default_value: mixed}>
     */
    private function getMethodParameters(ReflectionFunctionAbstract $method): array
    {
        // У ReflectionFunction нет getDeclaringClass()
        $declaringClass = $method instanceof \ReflectionMethod ? $method->getDeclaringClass() : null;
        $className = $declaringClass?->getName() ?? '';
        $cacheKey = $className . '::' . $method->getName();

        // 2. Проверяем кэш.
        if (isset($this->metadataCache[$cacheKey])) {
            return $this->metadataCache[$cacheKey];
        }

        if ($this->persistentCache && $this->persistentCache->has($cacheKey)) {
            $paramsData = $this->persistentCache->get($cacheKey);
            if (is_array($paramsData)) {
                $this->metadataCache[$cacheKey] = $paramsData;
                return $paramsData;
            }
        }

        // 3. Если в кэше нет - анализируем и сохраняем.
        $paramsData = [];
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            // DI-инъекция возможна только по именованному класс-типу:
            // у ReflectionUnionType/IntersectionType нет isBuiltin()/getName()
            $type_name = $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
            $paramsData[] = [
                'name' => $param->getName(),
                'type_name' => $type_name,
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
     * Приоритеты:
     * 1. Параметр с типом Context (или именем Context — легаси).
     * 2. Именованные аргументы из payload.
     * 3. DI через фабрику/контейнер.
     * 4. Аргументы по порядку (из regex).
     * 5. Значение по умолчанию.
     * 6. Nullable -> null.
     *
     * @param ReflectionFunctionAbstract $reflectionMethod Рефлексия метода (или функции).
     * @param Context $context Контекст текущего события.
     * @param array $availableArgs Ассоциативный/числовой массив доступных аргументов (из payload/regex).
     * @return array Готовый массив аргументов для вызова.
     * @throws \RuntimeException Если значение для параметра определить не удалось.
     */
    public function getArguments(
        \ReflectionFunctionAbstract $reflectionMethod,
        Context $context,
        array $availableArgs = [],
    ): array {
        $finalArgs = [];

        $methodParams = $this->getMethodParameters($reflectionMethod);

        foreach ($methodParams as $param) {
            $paramName = $param['name'];
            $paramTypeName = $param['type_name'];

            // ПРИОРИТЕТ 1: Контекст выполнения (по типу; имя Context оставлено для совместимости)
            if ($paramTypeName === Context::class || $paramName === 'context' || $paramName === Context::class) {
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
                } catch (\Exception) {
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

            $declaring = $reflectionMethod instanceof \ReflectionMethod
                ? $reflectionMethod->getDeclaringClass()?->getName() . '::'
                : '';
            $controllerName = $declaring . $reflectionMethod->getName() . '()';
            throw new \RuntimeException(
                "Не удалось определить значение для параметра '{$paramName}' в методе '{$controllerName}'.",
            );
        }

        return $finalArgs;
    }
}
