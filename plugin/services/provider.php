<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Extension\SmartAuthors;
use SuperSoft\Plugin\System\SmartAuthors\Repository\PolicyRepository;
use SuperSoft\Plugin\System\SmartAuthors\Service\PersonalOwnerBinder;
use SuperSoft\Plugin\System\SmartAuthors\Service\PolicyResolver;
use SuperSoft\Plugin\System\SmartAuthors\Service\RequestCache;

return new class () implements ServiceProviderInterface {
    /**
     * Registers SmartAuthors services with the DI container.
     */
    public function register(Container $container): void
    {
        $container->set(
            RequestCache::class,
            static fn() => new RequestCache()
        );

        $container->set(
            PolicyRepository::class,
            static function (Container $container): PolicyRepository {
                /** @var DatabaseInterface $db */
                $db = $container->get(DatabaseInterface::class);
                /** @var RequestCache $cache */
                $cache = $container->get(RequestCache::class);

                return new PolicyRepository($db, $cache);
            }
        );

        $container->set(
            PolicyResolver::class,
            static function (Container $container): PolicyResolver {
                /** @var PolicyRepository $repository */
                $repository = $container->get(PolicyRepository::class);
                /** @var DatabaseInterface $db */
                $db = $container->get(DatabaseInterface::class);
                /** @var RequestCache $cache */
                $cache = $container->get(RequestCache::class);

                return new PolicyResolver($repository, $db, $cache);
            }
        );

        $container->set(
            AccessService::class,
            static function (Container $container): AccessService {
                /** @var PolicyResolver $resolver */
                $resolver = $container->get(PolicyResolver::class);

                return new AccessService($resolver);
            }
        );

        $container->set(
            PersonalOwnerBinder::class,
            static function (Container $container): PersonalOwnerBinder {
                /** @var PolicyRepository $repository */
                $repository = $container->get(PolicyRepository::class);
                /** @var DatabaseInterface $db */
                $db = $container->get(DatabaseInterface::class);
                /** @var RequestCache $cache */
                $cache = $container->get(RequestCache::class);

                return new PersonalOwnerBinder($repository, $db, $cache);
            }
        );

        $container->set(
            PluginInterface::class,
            static function (Container $container): SmartAuthors {
                $plugin = new SmartAuthors(
                    (array) PluginHelper::getPlugin('system', 'smartauthors')
                );

                /** @var CMSApplicationInterface $app */
                $app = Factory::getApplication();
                $plugin->setApplication($app);

                /** @var DatabaseInterface $db */
                $db = $container->get(DatabaseInterface::class);
                $plugin->setDatabase($db);

                /** @var PolicyRepository $repository */
                $repository = $container->get(PolicyRepository::class);
                /** @var PolicyResolver $resolver */
                $resolver = $container->get(PolicyResolver::class);
                /** @var AccessService $accessService */
                $accessService = $container->get(AccessService::class);
                /** @var PersonalOwnerBinder $ownerBinder */
                $ownerBinder = $container->get(PersonalOwnerBinder::class);
                /** @var RequestCache $cache */
                $cache = $container->get(RequestCache::class);

                $plugin->setServices($repository, $resolver, $accessService, $ownerBinder, $cache, $db);

                return $plugin;
            }
        );
    }
};
