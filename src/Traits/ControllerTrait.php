<?php

namespace Scaleplan\Main\Traits;

use Scaleplan\Db\PgDb;
use Scaleplan\DTO\DTO;
use Scaleplan\DTO\Exceptions\PropertyNotFoundException;
use Scaleplan\Form\Form;
use Scaleplan\Form\Interfaces\FormInterface;
use Scaleplan\Http\Exceptions\NotFoundException;
use Scaleplan\HttpStatus\HttpStatusCodes;
use Scaleplan\Main\AbstractController;
use Scaleplan\Main\Constants\ConfigConstants;
use Scaleplan\Main\Exceptions\ControllerException;
use Scaleplan\Result\Interfaces\DbResultInterface;
use Scaleplan\Result\Interfaces\HTMLResultInterface;
use Scaleplan\Result\Interfaces\ResultInterface;
use Symfony\Component\Yaml\Yaml;
use function Scaleplan\DependencyInjection\get_required_container;
use function Scaleplan\Helpers\get_required_env;
use function Scaleplan\Translator\translate;

/**
 * Trait ControllerTrait
 *
 * @package Scaleplan\Main\Traits
 */
trait ControllerTrait
{
    /**
     * Шаблон формы добавления/изменения объекта
     *
     * @param string $type - тип формы ('put' - добавление или 'update' - изменение)
     *
     * @return Form
     *
     * @throws \ReflectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ContainerTypeNotSupportingException
     * @throws \Scaleplan\DependencyInjection\Exceptions\DependencyInjectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ParameterMustBeInterfaceNameOrClassNameException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ReturnTypeMustImplementsInterfaceException
     * @throws \Scaleplan\Helpers\Exceptions\EnvNotFoundException
     */
    private function getForm(string $type = 'put') : Form
    {
        $formConfig = Yaml::parse(
            file_get_contents(
                get_required_env(ConfigConstants::BUNDLE_PATH)
                . get_required_env(ConfigConstants::VIEWS_PATH)
                . get_required_env(AbstractController::FORMS_PATH_ENV_NAME)
                . '/'
                . strtolower($this->getModelName())
                . '.yml'
            )
        );
        /** @var AbstractController $this */
        $form = get_required_container(FormInterface::class, [$formConfig]);

        if (!empty($form->getFormConf()['form']['action'][$type])) {
            $form->setFormAction($form->getFormConf()['form']['action'][$type]);
        }

        if (!empty($form->getFormConf()['title']['text'][$type])) {
            $form->setTitleText($form->getFormConf()['title']['text'][$type]);
        }

        return $form;
    }

    /**
     * Форма создания
     *
     * @param FormInterface|null $form
     *
     * @return HTMLResultInterface
     *
     * @throws \ReflectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ContainerTypeNotSupportingException
     * @throws \Scaleplan\DependencyInjection\Exceptions\DependencyInjectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ParameterMustBeInterfaceNameOrClassNameException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ReturnTypeMustImplementsInterfaceException
     * @throws \Scaleplan\Helpers\Exceptions\EnvNotFoundException
     * @throws \Exception
     */
    public function actionCreate(FormInterface $form = null) : HTMLResultInterface
    {
        $form = $form ?? $this->getForm();
        return get_required_container(HTMLResultInterface::class, [$form->render()]);
    }

    /**
     * Форма редактирования модели
     *
     * @param DTO $idDto - идентификатор модели
     * @param DbResultInterface|null $model - Данные для заполнения формы
     * @param FormInterface|null $form
     *
     * @return HTMLResultInterface
     *
     * @throws ControllerException
     * @throws \ReflectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ContainerTypeNotSupportingException
     * @throws \Scaleplan\DependencyInjection\Exceptions\DependencyInjectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ParameterMustBeInterfaceNameOrClassNameException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ReturnTypeMustImplementsInterfaceException
     * @throws \Scaleplan\Form\Exceptions\FieldException
     * @throws \Scaleplan\Form\Exceptions\FormException
     * @throws \Scaleplan\Form\Exceptions\RadioVariantException
     * @throws \Scaleplan\Helpers\Exceptions\EnvNotFoundException
     * @throws \Exception
     */
    public function actionEdit(
        DTO $idDto,
        DbResultInterface $model = null,
        FormInterface $form = null
    ) : HTMLResultInterface
    {
        /** @var AbstractController $this */
        $repo = $this->getRepository();
        if (!$repo) {
            throw new ControllerException(translate('main.repo-not-found'));
        }
        $model = $model ?? $repo->getFullInfo($idDto);
        if (!$model->getResult()) {
            throw new NotFoundException(
                translate('main.object-with-id-not-found'),
                HttpStatusCodes::HTTP_NOT_FOUND
            );
        }

        $form = $form ?? $this->getForm('update');
        if (!isset($idDto->id)) {
            throw new PropertyNotFoundException('id');
        }

        $form->addIdField($idDto->getId());
        $form->setFormValues($model->getFirstResult());

        return get_required_container(HTMLResultInterface::class, [$form->render()]);
    }

    /**
     * Сохранить новую модель
     *
     * @param $data
     *
     * @return DbResultInterface
     *
     * @throws ControllerException
     * @throws \ReflectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ContainerTypeNotSupportingException
     * @throws \Scaleplan\DependencyInjection\Exceptions\DependencyInjectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ParameterMustBeInterfaceNameOrClassNameException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ReturnTypeMustImplementsInterfaceException
     */
    public function actionPut($data) : DbResultInterface
    {
        try {
            /** @var AbstractController $this */
            $repo = $this->getRepository();

            /** @var DbResultInterface $result */
            $result = $repo->put($data);
            if (!$result->getResult()) {
                throw new ControllerException(translate('main.object-creating-failed'));
            }
        } catch (\PDOException $e) {
            if (in_array($e->getCode(), PgDb::DUPLICATE_ERROR_CODES, false)) {
                throw new ControllerException(
                    translate('main.object-already-exist'),
                    null,
                    HttpStatusCodes::HTTP_CONFLICT
                );
            }

            throw $e;
        }

        return $result;
    }

    /**
     * Изменить модель
     *
     * @param DTO $id
     * @param DTO $dto
     *
     * @return DbResultInterface
     *
     * @throws ControllerException
     * @throws \ReflectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ContainerTypeNotSupportingException
     * @throws \Scaleplan\DependencyInjection\Exceptions\DependencyInjectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ParameterMustBeInterfaceNameOrClassNameException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ReturnTypeMustImplementsInterfaceException
     */
    public function actionUpdate(DTO $id, DTO $dto) : DbResultInterface
    {
        /** @var AbstractController $this */
        $repo = $this->getRepository();
        if (!$repo) {
            throw new ControllerException(translate('main.repo-not-found'));
        }

        if (!$dto->toSnakeArray()) {
            throw new ControllerException(translate('main.no-data-to-update'));
        }

        /** @var DbResultInterface $result */
        $result = $repo->update($id->toSnakeArray() + $dto->toSnakeArray());
        if (!$result->getResult()) {
            throw new ControllerException(
                translate('main.object-update-failed'),
                null,
                HttpStatusCodes::HTTP_NOT_FOUND
            );
        }

        return $result;
    }

    /**
     * Удаление модели
     *
     * @param DTO $id - идентификатор модели
     *
     * @return DbResultInterface
     *
     * @throws ControllerException
     * @throws \ReflectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ContainerTypeNotSupportingException
     * @throws \Scaleplan\DependencyInjection\Exceptions\DependencyInjectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ParameterMustBeInterfaceNameOrClassNameException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ReturnTypeMustImplementsInterfaceException
     */
    public function actionDelete(DTO $id) : DbResultInterface
    {
        /** @var AbstractController $this */
        $repo = $this->getRepository();
        if (!$repo) {
            throw new ControllerException(translate('main.repo-not-found'));
        }

        /** @var DbResultInterface $result */
        $result = $repo->delete($id);
        if (!$result->getResult()) {
            throw new ControllerException(translate('main.object-delete-failed'), null, HttpStatusCodes::HTTP_NOT_FOUND);
        }

        return $result;
    }

    /**
     * @param DTO $dto
     *
     * @return ResultInterface
     *
     * @throws ControllerException
     * @throws \PhpQuery\Exceptions\PhpQueryException
     * @throws \ReflectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ContainerTypeNotSupportingException
     * @throws \Scaleplan\DependencyInjection\Exceptions\DependencyInjectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ParameterMustBeInterfaceNameOrClassNameException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ReturnTypeMustImplementsInterfaceException
     * @throws \Scaleplan\Helpers\Exceptions\EnvNotFoundException
     * @throws \Scaleplan\Main\Exceptions\ViewNotFoundException
     * @throws \Scaleplan\Templater\Exceptions\DomElementNotFoundException
     */
    public function actionInfo(DTO $dto) : ResultInterface
    {
        /** @var AbstractController $this */
        $repo = $this->getRepository();
        if (!$repo) {
            throw new ControllerException(translate('main.repo-not-found'));
        }

        /** @var DbResultInterface $result */
        $result = $repo->getInfo($dto);
        if (!$result->getResult()) {
            throw new ControllerException(
                translate('main.object-not-found'),
                null,
                HttpStatusCodes::HTTP_NOT_FOUND
            );
        }
        /** @var AbstractController $this */
        return $this->formatResponse($result);
    }

    /**
     * Полная информация о модели
     *
     * @param DTO $id - идентификатор модели
     *
     * @return ResultInterface
     *
     * @throws ControllerException
     * @throws NotFoundException
     * @throws \PhpQuery\Exceptions\PhpQueryException
     * @throws \ReflectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ContainerTypeNotSupportingException
     * @throws \Scaleplan\DependencyInjection\Exceptions\DependencyInjectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ParameterMustBeInterfaceNameOrClassNameException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ReturnTypeMustImplementsInterfaceException
     * @throws \Scaleplan\Helpers\Exceptions\EnvNotFoundException
     * @throws \Scaleplan\Main\Exceptions\ViewNotFoundException
     * @throws \Scaleplan\Templater\Exceptions\DomElementNotFoundException
     */
    public function actionFullInfo(DTO $id) : ResultInterface
    {
        /** @var AbstractController $this */
        $repo = $this->getRepository();
        if (!$repo) {
            throw new ControllerException(translate('main.repo-not-found'));
        }

        /** @var DbResultInterface $result */
        $result = $repo->getFullInfo($id);
        if (!$result->getResult()) {
            throw new NotFoundException(
                translate('main.object-with-id-not-found'),
                HttpStatusCodes::HTTP_NOT_FOUND
            );
        }
        /** @var AbstractController $this */
        return $this->formatResponse($result);
    }

    /**
     * Список объектов
     *
     * @param $data
     *
     * @return ResultInterface
     *
     * @throws ControllerException
     * @throws \ReflectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ContainerTypeNotSupportingException
     * @throws \Scaleplan\DependencyInjection\Exceptions\DependencyInjectionException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ParameterMustBeInterfaceNameOrClassNameException
     * @throws \Scaleplan\DependencyInjection\Exceptions\ReturnTypeMustImplementsInterfaceException
     */
    public function actionList($data) : ResultInterface
    {
        /** @var AbstractController $this */
        $repo = $this->getRepository();
        if (!$repo) {
            throw new ControllerException(translate('main.repo-not-found'));
        }

        if ($data instanceof DTO) {
            $data = $data->toFullSnakeArray();
        }

        if (array_key_exists('id', $data) && $data['id'] === null) {
            unset($data['id']);
        }

        $result = $repo->getList($data);

        return $this->formatResponse($result);
    }
}
