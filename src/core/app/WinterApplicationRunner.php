<?php

declare(strict_types=1);

namespace dev\winterframework\core\app;

use dev\winterframework\core\apc\ApcCache;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\PropertyContext;
use dev\winterframework\core\context\WinterApplicationContext;
use dev\winterframework\core\context\WinterPropertyContext;
use dev\winterframework\enums\Winter;
use dev\winterframework\exception\NotWinterApplicationException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\io\file\DirectoryScanner;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResources;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\reflection\Psr4Namespace;
use dev\winterframework\reflection\Psr4Namespaces;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\cache\EnableCaching;
use dev\winterframework\stereotype\txn\EnableTransactionManagement;
use dev\winterframework\stereotype\WinterBootApplication;
use dev\winterframework\type\StringList;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\LoggerManager;
use dev\winterframework\util\PropertyLoader;

abstract class WinterApplicationRunner {

    protected WinterApplicationContext $applicationContext;
    protected ClassResource $bootApp;
    protected WinterBootApplication $bootConfig;
    protected ApplicationContextData $appCtxData;
    protected ClassResourceScanner $scanner;
    protected ClassResources $resources;
    protected Psr4Namespaces $scanNamespaces;
    protected PropertyContext $propertyCtx;

    public function __construct() {
        $this->scanner = ClassResourceScanner::getDefaultScanner();
    }

    public final function run(string $appClass) {
        $this->bootApp = $this->buildBootApp($appClass);
        $this->processBootConfig();

        $this->propertyCtx = new WinterPropertyContext(
            $this->bootConfig->configDirectory,
            $this->bootConfig->profile
        );

        $this->buildApplicationLogger(
            $this->bootConfig->configDirectory,
            $this->bootConfig->profile
        );

        $this->scanAppNamespaces();

        $this->appCtxData = $this->buildApplicationContextData();
        $this->applicationContext = new WinterApplicationContext($this->appCtxData);

        $this->buildAppContext();

        $this->startBootApp();
    }

    protected function buildBootApp(string $appClass): ClassResource {
        $resource = $this->scanner->scanClass(
            $appClass,
            StringList::ofValues(WinterBootApplication::class)
        );

        if ($resource == null) {
            throw new NotWinterApplicationException(
                "Could not find WinterBootApplication for class '$appClass'");
        }
        return $resource;
    }

    protected function startBootApp(): void {
        $this->applicationContext->beanByClass($this->bootApp->getClass()->getName());
        $this->runBootApp();
    }

    protected function buildAppContext(): void {
        $this->applicationContext->buildContext();
    }

    private function buildApplicationContextData(): ApplicationContextData {

        $data = new ApplicationContextData();
        $data->setScanner($this->scanner);
        $data->setBootApp($this->bootApp);
        $data->setBootConfig($this->bootConfig);
        $data->setResources($this->resources);
        $data->setPropertyContext($this->propertyCtx);

        return $data;
    }

    private function processBootConfig(): void {
        /** @var WinterBootApplication $bootConfig */
        $bootConfig = $this->bootApp->getAttribute(WinterBootApplication::class);
        if (empty($bootConfig->configDirectory)) {
            throw new WinterException('configDirectory is empty for application '
                . ReflectionUtil::getFqName($this->bootApp));
        }
        TypeAssert::stringArray($bootConfig->configDirectory,
            ' configDirectory is not configured well, please follow documentation');

        if (empty($bootConfig->scanNamespaces)) {
            throw new WinterException('scanNamespaces is empty for application '
                . ReflectionUtil::getFqName($this->bootApp));
        }

        $this->scanNamespaces = Psr4Namespaces::ofValues();
        foreach ($bootConfig->scanNamespaces as $nsRow) {
            TypeAssert::array($nsRow,
                ' scanNamespaces is not configured well, please follow documentation');
            TypeAssert::string($nsRow[0],
                ' scanNamespaces is not configured well, please follow documentation');
            TypeAssert::string($nsRow[1],
                ' scanNamespaces is not configured well, please follow documentation');

            $this->scanNamespaces[] = new Psr4Namespace($nsRow[0], $nsRow[1]);
        }

        TypeAssert::stringArray($bootConfig->scanExcludeNamespaces,
            ' scanExcludeNamespaces is not configured well, please follow documentation');

        $this->bootConfig = $bootConfig;
    }

    private function scanAppNamespaces(): void {
        $key = $this->bootApp->getClass()->getName() . '.resources';
        if (ApcCache::isEnabled()) {
            //ApcCache::delete($key);
            if (ApcCache::exists($key)
                && !$this->propertyCtx->getBool('winter.namespaces.cacheDisabled',false)) {
                $this->resources = ApcCache::get($key);
            }
        }

        if (!isset($this->resources)) {
            $this->resources = $this->scanner->scan(
                $this->nameSpacesToScan($this->scanNamespaces),
                $this->attributesToScan(),
                $this->bootConfig->autoload,
                $this->bootConfig->scanExcludeNamespaces
            );
            //print_r($this->resources);

            if (ApcCache::isEnabled()) {
                $ttl = $this->propertyCtx->getInt(
                    'winter.namespaces.cacheTime',
                    Winter::NAMESPACE_CACHE_TTL
                );
                ApcCache::cache($key, $this->resources, $ttl > 0 ? $ttl : Winter::NAMESPACE_CACHE_TTL);
            }
        }
        //print_r($this->resources);
    }

    private function nameSpacesToScan(Psr4Namespaces $ns): Psr4Namespaces {
        return $ns;
    }

    private function attributesToScan(): StringList {
        $stereoTypes = $this->scanner->getDefaultStereoTypes();

        if ($this->bootApp->getAttribute(EnableCaching::class) != null) {
            $cacheTypes = array_keys(
                DirectoryScanner::scanForPhpClasses(
                    dirname(dirname(__DIR__)) . '/cache/stereotype',
                    'dev\\winterframework\\cache\\stereotype'
                )
            );
            $stereoTypes->addAll($cacheTypes);
        }

        if ($this->bootApp->getAttribute(EnableTransactionManagement::class) != null) {
            $cacheTypes = array_keys(
                DirectoryScanner::scanForPhpClasses(
                    dirname(dirname(__DIR__)) . '/txn/stereotype',
                    'dev\\winterframework\\txn\\stereotype'
                )
            );
            $stereoTypes->addAll($cacheTypes);
        }

        return $stereoTypes;
    }

    private function buildApplicationLogger(array $configDirs, ?string $profile = null): void {
        $suffix = (isset($this->profile) && strlen($profile) ? '-' . $profile : '');
        $logFiles = [
            'logger' . $suffix . '.yml',
            'logger' . '.yml',
        ];

        $data = null;
        foreach ($logFiles as $logFile) {
            $configFiles = DirectoryScanner::scanFileInDirectories($configDirs, $logFile);

            foreach ($configFiles as $configFile) {
                $data = PropertyLoader::loadLogging($configFile);
                break 2;
            }
        }

        if (empty($data)) {
            return;
        }

        LoggerManager::buildInstance($data);
    }

    protected abstract function runBootApp(): void;

}