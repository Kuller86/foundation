<?php

namespace Modera\BackendSecurityBundle\Controller;

use Modera\BackendSecurityBundle\ModeraBackendSecurityBundle;
use Modera\SecurityBundle\Entity\User;
use Modera\SecurityBundle\PasswordStrength\BadPasswordException;
use Modera\SecurityBundle\PasswordStrength\PasswordManager;
use Modera\SecurityBundle\Service\UserService;
use Modera\ServerCrudBundle\Controller\AbstractCrudController;
use Modera\ServerCrudBundle\DataMapping\DataMapperInterface;
use Modera\ServerCrudBundle\Hydration\HydrationProfile;
use Modera\ServerCrudBundle\Persistence\OperationResult;
use Modera\FoundationBundle\Translation\T;
use Modera\ServerCrudBundle\Validation\EntityValidatorInterface;
use Modera\ServerCrudBundle\Validation\ValidationResult;
use Psr\Log\LoggerInterface;
use Sli\ExtJsIntegrationBundle\QueryBuilder\Parsing\Filter;
use Sli\ExtJsIntegrationBundle\QueryBuilder\Parsing\Filters;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Modera\BackendSecurityBundle\Service\MailService;

/**
 * @author    Sergei Vizel <sergei.vizel@modera.org>
 * @copyright 2014 Modera Foundation
 */
class UsersController extends AbstractCrudController
{
    /**
     * @return array
     */
    public function getConfig()
    {
        $self = $this;

        return array(
            'entity' => User::clazz(),
            'create_default_data_mapper' => function (ContainerInterface $container) {
                return $this->container->get('modera_backend_security.data_mapper.user_data_mapper');
            },
            'security' => array(
                'actions' => array(
                    'create' => ModeraBackendSecurityBundle::ROLE_MANAGE_USER_PROFILES,
                    'update' => function (AuthorizationCheckerInterface $ac, array $params) use ($self) {
                        /* @var TokenStorageInterface $ts */
                        $ts = $self->get('security.token_storage');
                        /* @var User $user */
                        $user = $ts->getToken()->getUser();

                        if ($ac->isGranted(ModeraBackendSecurityBundle::ROLE_MANAGE_USER_PROFILES)) {
                            return true;
                        } else {
                            // irrespectively of what privileges user has we will always allow him to edit his
                            // own profile data
                            return $user instanceof User && isset($params['record']['id'])
                                   && $user->getId() == $params['record']['id'];
                        }
                    },
                    'remove' => ModeraBackendSecurityBundle::ROLE_MANAGE_USER_PROFILES,
                    'get' => function(AuthorizationCheckerInterface $ac, array $params) {
                        $userId = null;
                        if (isset($params['filter'])) {
                            foreach (new Filters($params['filter']) as $filter) {
                                /* @var Filter $filter */

                                if ($filter->getProperty() == 'id' && $filter->getComparator() == Filter::COMPARATOR_EQUAL) {
                                    $userId = $filter->getValue();
                                }
                            }
                        }

                        $isPossiblyEditingOwnProfile = null !== $userId;
                        if ($isPossiblyEditingOwnProfile) {
                            /* @var TokenStorageInterface $ts */
                            $ts = $this->get('security.token_storage');
                            /* @var User $user */
                            $user = $ts->getToken()->getUser();

                            if ($user->getId() == $userId) {
                                return true;
                            }
                        }

                        return $ac->isGranted(ModeraBackendSecurityBundle::ROLE_MANAGE_USER_PROFILES);
                    },
                    'list' => ModeraBackendSecurityBundle::ROLE_ACCESS_BACKEND_TOOLS_SECURITY_SECTION,
                    'batchUpdate' => ModeraBackendSecurityBundle::ROLE_MANAGE_USER_PROFILES,
                ),
            ),
            'hydration' => array(
                'groups' => array(
                    'main-form' => ['id', 'username', 'email', 'firstName', 'lastName', 'middleName', 'meta'],
                    'list' => function (User $user) {
                        $groups = array();
                        foreach ($user->getGroups() as $group) {
                            $groups[] = $group->getName();
                        }

                        return array(
                            'id' => $user->getId(),
                            'username' => $user->getUsername(),
                            'email' => $user->getEmail(),
                            'firstName' => $user->getFirstName(),
                            'lastName' => $user->getLastName(),
                            'middleName' => $user->getMiddleName(),
                            'isActive' => $user->isActive(),
                            'state' => $user->getState(),
                            'groups' => $groups,
                            'meta' => $user->getMeta(),
                        );
                    },
                    'compact-list' => function (User $user) {
                        $groups = array();
                        foreach ($user->getGroups() as $group) {
                            $groups[] = $group->getName();
                        }

                        return array(
                            'id' => $user->getId(),
                            'username' => $user->getUsername(),
                            'fullname' => $user->getFullName(),
                            'isActive' => $user->isActive(),
                            'state' => $user->getState(),
                        );
                    },
                    'delete-user' => ['username'],
                ),
                'profiles' => array(
                    'list',
                    'delete-user',
                    'main-form',
                    'compact-list',
                    'modera-backend-security-group-groupusers' => HydrationProfile::create(false)->useGroups(array('compact-list')),
                ),
            ),
            'map_data_on_create' => function (array $params, User $user, DataMapperInterface $defaultMapper, ContainerInterface $container) use ($self) {
                $defaultMapper->mapData($params, $user);

                if (isset($params['plainPassword']) && $params['plainPassword']) {
                    $plainPassword = $params['plainPassword'];
                } else {
                    $plainPassword = $this->getPasswordManager()->generatePassword();
                }

                try {
                    if (isset($params['sendPassword']) && $params['sendPassword'] != '') {
                        $this->getPasswordManager()->encodeAndSetPasswordAndThenEmailIt($user, $plainPassword);
                    } else {
                        $this->getPasswordManager()->encodeAndSetPassword($user, $plainPassword);
                    }
                } catch (BadPasswordException $e) {
                    throw new BadPasswordException($e->getErrors()[0], null, $e);
                }
            },
            'map_data_on_update' => function (array $params, User $user, DataMapperInterface $defaultMapper, ContainerInterface $container) use ($self) {
                $defaultMapper->mapData($params, $user);

                /* @var LoggerInterface $activityMgr */
                $activityMgr = $container->get('modera_activity_logger.manager.activity_manager');

                if (isset($params['active'])) {
                    /* @var UserService $userService */
                    $userService = $container->get('modera_security.service.user_service');
                    if ($params['active']) {
                        $userService->enable($user);
                        $activityMsg = T::trans('Profile enabled for user "%user%".', array('%user%' => $user->getUsername()));
                        $activityContext = array(
                            'type' => 'user.profile_enabled',
                            'author' => $this->getUser()->getId(),
                        );
                    } else {
                        $userService->disable($user);
                        $activityMsg = T::trans('Profile disabled for user "%user%".', array('%user%' => $user->getUsername()));
                        $activityContext = array(
                            'type' => 'user.profile_disabled',
                            'author' => $this->getUser()->getId(),
                        );
                    }
                    $activityMgr->info($activityMsg, $activityContext);
                } else if (isset($params['plainPassword']) && $params['plainPassword']) {
                    // Password encoding and setting is done in "updated_entity_validator"

                    $activityMsg = T::trans('Password has been changed for user "%user%".', array('%user%' => $user->getUsername()));
                    $activityContext = array(
                        'type' => 'user.password_changed',
                        'author' => $this->getUser()->getId(),
                    );
                    $activityMgr->info($activityMsg, $activityContext);
                } else {
                    $activityMsg = T::trans('Profile data is changed for user "%user%".', array('%user%' => $user->getUsername()));
                    $activityContext = array(
                        'type' => 'user.profile_updated',
                        'author' => $this->getUser()->getId(),
                    );
                    $activityMgr->info($activityMsg, $activityContext);
                }
            },
            'updated_entity_validator' => function (array $params, User $user, EntityValidatorInterface $validator, array $config) {
                $isBatchUpdatedBeingPerformed = !isset($params['record']);
                if ($isBatchUpdatedBeingPerformed) {
                    // Because of bug in AbstractCrudController (see MF-UPGRADE3.0 and search for "$recordParams" keyword)
                    // it is tricky to perform proper validation here, but anyway it not likely that at any
                    // time we are going to be setting passwords using a batch operation
                    return new ValidationResult();
                }

                $result = $validator->validate($user, $config);

                $params = $params['record'];
                if (isset($params['plainPassword']) && $params['plainPassword']) {
                    try {
                        // We are force to do it here because we have no access to validation in
                        // "map_data_on_update"
                        if (isset($params['sendPassword']) && $params['sendPassword'] != '') {
                            $this->getPasswordManager()->encodeAndSetPasswordAndThenEmailIt($user, $params['plainPassword']);
                        } else {
                            $this->getPasswordManager()->encodeAndSetPassword($user, $params['plainPassword']);
                        }
                    } catch (BadPasswordException $e) {
                        $result->addFieldError('plainPassword', $e->getErrors()[0]);
                    }
                }

                return $result;
            },
            'remove_entities_handler' => function ($entities, $params, $defaultHandler, ContainerInterface $container) {
                /* @var UserService $userService */
                $userService = $container->get('modera_security.service.user_service');

                $operationResult = new OperationResult();

                foreach ($entities as $entity) {
                    /* @var User $entity*/
                    $userService->remove($entity);

                    $operationResult->reportEntity(User::clazz(), $entity->getId(), OperationResult::TYPE_ENTITY_REMOVED);
                }

                return $operationResult;
            },
        );
    }

    /**
     * @Remote
     */
    public function generatePasswordAction(array $params)
    {
        /* @var User $authenticatedUser */
        $authenticatedUser = $this->getUser();

        $targetUser = null;
        if (isset($params['userId'])) {
            /* @var User $requestedUser */
            $requestedUser = $this
                ->getDoctrine()
                ->getRepository(User::class)
                ->find($params['userId'])
            ;

            if ($requestedUser) {
                if (!$authenticatedUser->isEqualTo($requestedUser)) {
                    $this->denyAccessUnlessGranted(ModeraBackendSecurityBundle::ROLE_MANAGE_USER_PROFILES);
                }

                $targetUser = $requestedUser;
            } else {
                throw $this->createAccessDeniedException();
            }
        } else {
            $targetUser = $authenticatedUser;
        }

        return array(
            'success' => true,
            'result' => array(
                'plainPassword' => $this->getPasswordManager()->generatePassword($targetUser),
            ),
        );
    }

    /**
     * @Remote
     */
    public function isPasswordRotationNeededAction(array $params)
    {
        return array(
            'success' => true,
            'result' => array(
                'isRotationNeeded' => $this->getPasswordManager()->isItTimeToRotatePassword($this->getUser()),
            ),
        );
    }

    /**
     * @return PasswordManager
     */
    private function getPasswordManager()
    {
        return $this->get('modera_security.password_strength.password_manager');
    }
}
