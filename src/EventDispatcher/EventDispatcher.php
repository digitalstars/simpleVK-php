<?php

namespace DigitalStars\SimpleVK\EventDispatcher;

use DigitalStars\SimpleVK\SimpleVK;
use DigitalStars\SimpleVK\Store;
use DigitalStars\SimpleVK\Attributes\{AsButton, Trigger, Fallback};
use ReflectionClass, ReflectionMethod;
use RecursiveIteratorIterator, RecursiveDirectoryIterator;

class EventDispatcher
{
    private SimpleVK $vk;
    private array $config;
    private array $routeMap = [
        'payload' => [],
        'command' => [],
        'regex' => [],
    ];
    private ?string $fallbackAction = null;
    private bool $isRoutesLoaded = false;
    private array $actionPaths = [];

    public function __construct(SimpleVK $vk, array $config = [])
    {
        $this->vk = $vk;
        $this->config = $config;
        if (isset($config['actions_paths'])) {
            $this->registerActionPaths($config['actions_paths']);
        }
    }

    public function registerActionPaths(array $paths): self
    {
        $this->actionPaths = array_merge($this->actionPaths, $paths);
        return $this;
    }

    public function handle(?array $externalEvent = null): void
    {
        if (!$this->isRoutesLoaded) {
            $this->loadRoutes();
        }

        $event = $externalEvent ?? $this->vk->data;
        $this->vk->data = $event;
        $this->vk->initVars($peerId, $userId, $type, $text, $payload, $messageId, $attachments);

        if (is_null($userId)) {
            return;
        }

        $matchedAction = null;
        $actionArgs = [];

        if (!empty($payload['action'])) {
            $actionName = $payload['action'];
            if (isset($this->routeMap['payload'][$actionName])) {
                $matchedAction = $this->routeMap['payload'][$actionName];
                $actionArgs = array_diff_key($payload, ['action' => '']);
            }
        }

        if (!$matchedAction && !empty($text)) {
            if (isset($this->routeMap['command'][$text])) {
                $matchedAction = $this->routeMap['command'][$text];
            } else {
                foreach ($this->routeMap['regex'] as $pattern => $actionClass) {
                    if (preg_match($pattern, $text, $matches)) {
                        $matchedAction = $actionClass;
                        $actionArgs = array_slice($matches, 1);
                        break;
                    }
                }
            }
        }

        if (!$matchedAction) {
            $matchedAction = $this->fallbackAction;
        }

        if ($matchedAction) {
            $this->executeAction($matchedAction, $userId, $peerId, $actionArgs, $event);
        }
    }

    private function executeAction(string $actionClass, int $userId, int $peerId, array $args, array $rawEvent): void
    {
        $store = Store::load($userId);
        $this->vk->initText($text)->initPayload($payload);

        $context = new Context($this->vk, $this, $store, (object)$rawEvent, $userId, $peerId, $text, $payload);
        $instance = new $actionClass();

        $instance->setContext($context);

        if (!$instance->before($context)) {
            return;
        }

        $reflectionMethod = new ReflectionMethod($instance, 'handle');
        $finalArgs = [];
        $handleParams = $reflectionMethod->getParameters();

        array_shift($handleParams);

        foreach ($handleParams as $param) {
            $paramName = $param->getName();
            if (isset($args[$paramName])) {
                $finalArgs[] = $args[$paramName];
            } elseif (isset($args[0])) {
                $finalArgs[] = array_shift($args);
            } else {
                $finalArgs[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
            }
        }

        $instance->handle($context, ...$finalArgs);
    }

    private function loadRoutes(): void
    {
        if ($this->isRoutesLoaded) {
            return;
        }
        foreach ($this->actionPaths as $path) {
            $this->scanDirectoryForActions($path);
        }
        $this->isRoutesLoaded = true;
    }

    private function scanDirectoryForActions(string $path): void
    {
        $realPath = realpath($path);
        if (!$realPath) {
            return;
        }

        $autoloaderRoot = $this->config['autoloader_root'] ?? dirname($realPath);
        $rootNamespace = $this->config['root_namespace'] ?? '';

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realPath));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($autoloaderRoot, '', $file->getRealPath());
            $classPath = substr(ltrim($relativePath, DIRECTORY_SEPARATOR), 0, -4);
            $className = $rootNamespace . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $classPath);

            if (!class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            if ($reflection->isAbstract()) {
                continue;
            }

            $isButton = $reflection->isSubclassOf(BaseButton::class);

            $asButtonAttr = $reflection->getAttributes(AsButton::class)[0] ?? null;
            if ($asButtonAttr) {
                if ($isButton) {
                    $instance = $asButtonAttr->newInstance();
                    $actionName = $instance->payload ?? $reflection->getShortName();
                    $this->routeMap['payload'][$actionName] = $className;
                } else {
                    trigger_error(
                        "Attribute #[AsButton] can only be used on classes that extend BaseButton. Class: '{$className}'.",
                        E_USER_WARNING
                    );
                }
            }

            $triggerAttrs = $reflection->getAttributes(Trigger::class);
            foreach ($triggerAttrs as $attribute) {
                $instance = $attribute->newInstance();
                if ($instance->command) {
                    $this->routeMap['command'][$instance->command] = $className;
                }
                if ($instance->pattern) {
                    $this->routeMap['regex'][$instance->pattern] = $className;
                }
            }

            if ($reflection->getAttributes(Fallback::class)) {
                $this->fallbackAction = $className;
            }
        }
    }
}