<?php

namespace Scaleplan\Main;

use phpDocumentor\Reflection\DocBlock;
use Scaleplan\Data\Data;
use Scaleplan\Data\Interfaces\DataInterface;
use function Scaleplan\DependencyInjection\get_required_container;
use function Scaleplan\DependencyInjection\get_static_container;
use Scaleplan\DTO\DTO;
use Scaleplan\Helpers\ArrayHelper;
use function Scaleplan\Helpers\get_required_env;
use Scaleplan\Helpers\Helper;
use Scaleplan\Main\Constants\ConfigConstants;
use Scaleplan\Main\Exceptions\RepositoryMethodArgsInvalidException;
use Scaleplan\Main\Exceptions\RepositoryMethodNotFoundException;
use Scaleplan\Result\Interfaces\DbResultInterface;

/**
 * Class AbstractRepository
 *
 * @package Scaleplan\Main
 *
 * @method DbResultInterface getFullInfo(array|DTO $data)
 * @method DbResultInterface put(array|DTO $data)
 * @method DbResultInterface update(DTO $id, array|DTO $data)
 * @method DbResultInterface delete(DTO $id)
 * @method DbResultInterface getInfo(DTO $id)
 * @method DbResultInterface getList(array|DTO $data)
 * @method DbResultInterface deactivate(array|DTO $data)
 */
abstract class AbstractRepository
{
    public const TABLE                  = null;
    public const DEFAULT_SORT_DIRECTION = 'DESC';

    public const DB_NAME_TAG   = 'dbName';
    public const PREFIX_TAG    = 'prefix';
    public const MODIFYING_TAG = 'modifying';
    public const MODEL_TAG     = 'model';

    public const DB_CACHE_ENV = 'DB_CACHE_ENABLE';

    /**
     * Вернуть имя базы данных в зависимости от субдомена
     *
     * @param DocBlock $docBlock - блок описания метода получения имени базы данных
     *
     * @return string
     *
     * @throws \Scaleplan\Helpers\Exceptions\EnvNotFoundException
     */
    public static function getDbName(DocBlock $docBlock) : string
    {
        $docParam = $docBlock->getTagsByName(static::DB_NAME_TAG)[0] ?? null;
        if (!$docParam) {
            return get_required_env(ConfigConstants::DEFAULT_DB);
        }

        $dbName = trim($docParam->getDescription());

        switch ($dbName) {
            case '$current':
                return Helper::getSubdomain();

            default:
                return $dbName;
        }
    }

    /**
     * Получить префикс, который нужно добавить к именам полей результата запроса к БД
     *
     * @param null|DocBlock $docBlock - блок описания префикса
     *
     * @return string|null
     */
    public static function getPrefix(DocBlock $docBlock) : ?string
    {
        $docParam = $docBlock->getTagsByName(static::PREFIX_TAG)[0] ?? null;
        if (!$docParam) {
            return null;
        }

        return trim($docParam->getDescription());
    }

    /**
     * @param DocBlock $docBlock
     *
     * @return bool
     */
    public static function isModifying(DocBlock $docBlock) : bool
    {
        return (bool)$docBlock->getTagsByName(static::MODIFYING_TAG);
    }

    /**
     * @param DocBlock $docBlock
     *
     * @return string|null
     */
    public static function getModelClass(DocBlock $docBlock) : ?string
    {
        $docParam = $docBlock->getTagsByName(static::MODEL_TAG)[0] ?? null;
        if (!$docParam) {
            return null;
        }

        return trim($docParam->getDescription());
    }

    /**
     * @param string $propertyName
     *
     * @return \ReflectionClassConstant|\ReflectionProperty
     *
     * @throws RepositoryMethodNotFoundException
     * @throws \ReflectionException
     */
    private static function getReflector(string $propertyName) : \Reflector
    {
        if (\defined("static::$propertyName")) {
            return new \ReflectionClassConstant(static::class, $propertyName);
        }

        if (property_exists(static::class, $propertyName)) {
            return new \ReflectionProperty(static::class, $propertyName);
        }

        throw new RepositoryMethodNotFoundException();
    }

    /**
     * @param string $propertyName
     * @param array $params
     * @param object|null $object
     *
     * @return DbResultInterface
     *
     * @throws Exceptions\DatabaseException
     * @throws RepositoryMethodArgsInvalidException
     * @throws RepositoryMethodNotFoundException
     * @throws \ReflectionException
     * @throws \Scaleplan\Data\Exceptions\DataException
     * @throws \Scaleplan\Data\Exceptions\ValidationException
     * @throws \Scaleplan\Db\Exceptions\ConnectionStringException
     * @throws \Scaleplan\Db\Exceptions\PDOConnectionException
     * @throws \Scaleplan\Db\Exceptions\QueryCountNotMatchParamsException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ContainerTypeNotSupportingException
     * @throws \Scaleplan\DependencyInjection\Exceptions\DependencyInjectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ParameterMustBeInterfaceNameOrClassNameException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ReturnTypeMustImplementsInterfaceException
     * @throws \Scaleplan\Helpers\Exceptions\EnvNotFoundException
     * @throws \Scaleplan\Result\Exceptions\ResultException
     */
    public static function invoke(string $propertyName, array $params, object $object = null) : DbResultInterface
    {
        if ($params && empty($params[0])) {
            throw new RepositoryMethodArgsInvalidException($propertyName);
        }

        if ($params && \is_array($params[0]) && !ArrayHelper::isAccos($params[0])) {
            throw new RepositoryMethodArgsInvalidException($propertyName);
        }

        $params && $params = $params[0];
        if ($params instanceof DTO) {
            $params = $params->toSnakeArray();
        }

        $reflector = static::getReflector($propertyName);
        if ($reflector instanceof \ReflectionClassConstant) {
            $sql = $reflector->getValue();
        } else {
            $sql = $reflector->getValue($object);
        }

        $docBlock = new DocBlock($reflector->getDocComment());

        /** @var App $app */
        $app = get_static_container(App::class);
        /** @var Data $data */
        $data = get_required_container(DataInterface::class, [$sql, $params]);
        $data->setDbConnect($app::getDB(static::getDbName($docBlock)));
        $data->setPrefix(static::getPrefix($docBlock));
        if (static::isModifying($docBlock)) {
            $data->setIsModifying();
        }

        $data->setCacheEnable((bool)getenv(static::DB_CACHE_ENV));

        $result = $data->getValue();
        $result->setModelClass(static::getModelClass($docBlock));

        return $result;
    }

    /**
     * @param string $propertyName
     * @param array $data
     *
     * @return DbResultInterface
     *
     * @throws Exceptions\DatabaseException
     * @throws RepositoryMethodArgsInvalidException
     * @throws RepositoryMethodNotFoundException
     * @throws \ReflectionException
     * @throws \Scaleplan\Data\Exceptions\DataException
     * @throws \Scaleplan\Data\Exceptions\ValidationException
     * @throws \Scaleplan\Db\Exceptions\ConnectionStringException
     * @throws \Scaleplan\Db\Exceptions\PDOConnectionException
     * @throws \Scaleplan\Db\Exceptions\QueryCountNotMatchParamsException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ContainerTypeNotSupportingException
     * @throws \Scaleplan\DependencyInjection\Exceptions\DependencyInjectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ParameterMustBeInterfaceNameOrClassNameException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ReturnTypeMustImplementsInterfaceException
     * @throws \Scaleplan\Helpers\Exceptions\EnvNotFoundException
     * @throws \Scaleplan\Result\Exceptions\ResultException
     */
    public static function __callStatic(string $propertyName, array $data) : DbResultInterface
    {
        return static::invoke($propertyName, $data);
    }

    /**
     * @param string $propertyName
     * @param array $data
     *
     * @return DbResultInterface
     *
     * @throws Exceptions\DatabaseException
     * @throws RepositoryMethodArgsInvalidException
     * @throws RepositoryMethodNotFoundException
     * @throws \ReflectionException
     * @throws \Scaleplan\Data\Exceptions\DataException
     * @throws \Scaleplan\Data\Exceptions\ValidationException
     * @throws \Scaleplan\Db\Exceptions\ConnectionStringException
     * @throws \Scaleplan\Db\Exceptions\PDOConnectionException
     * @throws \Scaleplan\Db\Exceptions\QueryCountNotMatchParamsException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ContainerTypeNotSupportingException
     * @throws \Scaleplan\DependencyInjection\Exceptions\DependencyInjectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ParameterMustBeInterfaceNameOrClassNameException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ReturnTypeMustImplementsInterfaceException
     * @throws \Scaleplan\Helpers\Exceptions\EnvNotFoundException
     * @throws \Scaleplan\Result\Exceptions\ResultException
     */
    public function __call(string $propertyName, array $data) : DbResultInterface
    {
        return static::invoke($propertyName, $data, $this);
    }
}
