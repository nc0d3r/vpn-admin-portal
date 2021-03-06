<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2018, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

$baseDir = dirname(__DIR__);
/** @psalm-suppress UnresolvableInclude */
require_once sprintf('%s/vendor/autoload.php', $baseDir);

use fkooman\SeCookie\Cookie;
use fkooman\SeCookie\Session;
use SURFnet\VPN\Admin\AdminPortalModule;
use SURFnet\VPN\Admin\Graph;
use SURFnet\VPN\Admin\TwigFilters;
use SURFnet\VPN\Common\Config;
use SURFnet\VPN\Common\Http\CsrfProtectionHook;
use SURFnet\VPN\Common\Http\FormAuthenticationHook;
use SURFnet\VPN\Common\Http\FormAuthenticationModule;
use SURFnet\VPN\Common\Http\HtmlResponse;
use SURFnet\VPN\Common\Http\LanguageSwitcherHook;
use SURFnet\VPN\Common\Http\LdapAuth;
use SURFnet\VPN\Common\Http\MellonAuthenticationHook;
use SURFnet\VPN\Common\Http\PdoAuth;
use SURFnet\VPN\Common\Http\RadiusAuth;
use SURFnet\VPN\Common\Http\Request;
use SURFnet\VPN\Common\Http\Service;
use SURFnet\VPN\Common\Http\SimpleAuth;
use SURFnet\VPN\Common\Http\TwoFactorHook;
use SURFnet\VPN\Common\Http\TwoFactorModule;
use SURFnet\VPN\Common\HttpClient\CurlHttpClient;
use SURFnet\VPN\Common\HttpClient\ServerClient;
use SURFnet\VPN\Common\LdapClient;
use SURFnet\VPN\Common\Logger;
use SURFnet\VPN\Common\TwigTpl;

$logger = new Logger('vpn-admin-portal');

// on various systems we have various font locations
// XXX move this to configuration
$fontList = [
    '/usr/share/fonts/google-roboto/Roboto-Regular.ttf', // Fedora (google-roboto-fonts)
    '/usr/share/fonts/roboto_fontface/roboto/Roboto-Regular.ttf', // Fedora (roboto-fontface-fonts)
    '/usr/share/fonts/roboto_fontface/Roboto-Regular.ttf', // CentOS (roboto-fontface-fonts)
    '/usr/share/fonts-roboto-fontface/fonts/Roboto-Regular.ttf', // Debian (fonts-roboto-fontface)
];

try {
    $request = new Request($_SERVER, $_GET, $_POST);

    if (false === $instanceId = getenv('VPN_INSTANCE_ID')) {
        $instanceId = $request->getServerName();
    }

    $dataDir = sprintf('%s/data/%s', $baseDir, $instanceId);
    if (!file_exists($dataDir)) {
        if (false === @mkdir($dataDir, 0700, true)) {
            throw new RuntimeException(sprintf('unable to create folder "%s"', $dataDir));
        }
    }

    $config = Config::fromFile(sprintf('%s/config/%s/config.php', $baseDir, $instanceId));

    $templateDirs = [
        sprintf('%s/views', $baseDir),
        sprintf('%s/config/%s/views', $baseDir, $instanceId),
    ];
    if ($config->hasItem('styleName')) {
        $templateDirs[] = sprintf('%s/views/%s', $baseDir, $config->getItem('styleName'));
    }

    $templateCache = null;
    if ($config->getItem('enableTemplateCache')) {
        $templateCache = sprintf('%s/tpl', $dataDir);
    }

    $cookie = new Cookie(
        [
            'SameSite' => 'Lax',
            'Secure' => $config->getItem('secureCookie'),
            'Max-Age' => 60 * 60 * 24 * 90,   // 90 days
        ]
    );

    $session = new Session(
        [
            'SessionName' => 'SID',
            'DomainBinding' => $request->getServerName(),
            'PathBinding' => $request->getRoot(),
        ],
        new Cookie(
            [
                // we need to bind to "Path", otherwise the (Basic)
                // authentication mechanism will set a cookie for
                // {ROOT}/_form/auth/
                'Path' => $request->getRoot(),
                'SameSite' => 'Lax',
                'Secure' => $config->getItem('secureCookie'),
            ]
        )
    );

    $tpl = new TwigTpl($templateDirs, $baseDir.'/locale', 'VpnAdminPortal', $templateCache);
    $tpl->addFilter(TwigFilters::sizeToHuman());
    $tpl->setDefault(
        [
            'requestUri' => $request->getUri(),
            'requestRoot' => $request->getRoot(),
            'requestRootUri' => $request->getRootUri(),
        ]
    );
    $supportedLanguages = $config->getSection('supportedLanguages')->toArray();
    $tpl->addDefault(
        [
            'supportedLanguages' => $supportedLanguages,
        ]
    );

    $service = new Service($tpl);
    $service->addBeforeHook('csrf_protection', new CsrfProtectionHook());
    $service->addBeforeHook('language_switcher', new LanguageSwitcherHook(array_keys($supportedLanguages), $cookie));

    // Authentication
    $authMethod = $config->getItem('authMethod');
    $tpl->addDefault(['authMethod' => $authMethod]);

    switch ($authMethod) {
        case 'MellonAuthentication':
            $mellonAuthentication = new MellonAuthenticationHook(
                $session,
                $config->getSection('MellonAuthentication')->getItem('attribute'),
                $config->getSection('MellonAuthentication')->getItem('addEntityID')
            );
            // check for userId authorization
            if ($config->getSection('MellonAuthentication')->hasItem('userIdAuthorization')) {
                $mellonAuthentication->enableUserIdAuthorization(
                    $config->getSection('MellonAuthentication')->getItem('userIdAuthorization')
                );
            }
            // check for entitlement authorization
            if ($config->getSection('MellonAuthentication')->hasItem('entitlementAttribute')) {
                $mellonAuthentication->enableEntitlementAuthorization(
                    $config->getSection('MellonAuthentication')->getItem('entitlementAttribute'),
                    $config->getSection('MellonAuthentication')->getItem('entitlementAuthorization')
                );
            }
            $service->addBeforeHook('auth', $mellonAuthentication);
            break;
        case 'FormLdapAuthentication':
            $tpl->addDefault(['_show_logout' => true]);
            $service->addBeforeHook(
                'auth',
                new FormAuthenticationHook(
                    $session,
                    $tpl
                )
            );
            $ldapClient = new LdapClient(
                $config->getSection('FormLdapAuthentication')->getItem('ldapUri')
            );
            $userAuth = new LdapAuth(
                $logger,
                $ldapClient,
                $config->getSection('FormLdapAuthentication')->getItem('userDnTemplate')
            );
            $service->addModule(
                new FormAuthenticationModule(
                    $userAuth,
                    $session,
                    $tpl
                )
            );

            break;
        case 'FormPdoAuthentication':
            $tpl->addDefault(['_show_logout' => true]);
            $service->addBeforeHook(
                'auth',
                new FormAuthenticationHook(
                    $session,
                    $tpl
                )
            );
            $userAuth = new PdoAuth(
                new PDO(
                    sprintf('sqlite://%s/data/%s/userdb.sqlite', $baseDir, $instanceId)
                )
            );
            $service->addModule(
                new FormAuthenticationModule(
                    $userAuth,
                    $session,
                    $tpl
                )
            );

            break;
        case 'FormRadiusAuthentication':
            $tpl->addDefault(['_show_logout' => true]);
            $service->addBeforeHook(
                'auth',
                new FormAuthenticationHook(
                    $session,
                    $tpl
                )
            );

            if ($config->getSection('FormRadiusAuthentication')->hasItem('serverList')) {
                $serverList = $config->getSection('FormRadiusAuthentication')->getItem('serverList');
            } else {
                // legacy way of configuring RADIUS servers, only one specified here
                // XXX remove for 2.0
                $serverList = [
                    [
                        'host' => $config->getSection('FormRadiusAuthentication')->getItem('host'),
                        'secret' => $config->getSection('FormRadiusAuthentication')->getItem('secret'),
$config->getSection('FormRadiusAuthentication')->hasItem('port') ? $config->getSection('FormRadiusAuthentication')->getItem('port') : 1812,
                    ],
                ];
            }

            $userAuth = new RadiusAuth($logger, $serverList);
            if ($config->getSection('FormRadiusAuthentication')->hasItem('addRealm')) {
                $userAuth->setRealm($config->getSection('FormRadiusAuthentication')->getItem('addRealm'));
            }
            if ($config->getSection('FormRadiusAuthentication')->hasItem('nasIdentifier')) {
                $userAuth->setNasIdentifier($config->getSection('FormRadiusAuthentication')->getItem('nasIdentifier'));
            }

            $service->addModule(
                new FormAuthenticationModule(
                    $userAuth,
                    $session,
                    $tpl
                )
            );

            break;
        case 'FormAuthentication':
            // XXX remove for 2.0
            $tpl->addDefault(['_show_logout' => true]);
            $service->addBeforeHook(
                'auth',
                new FormAuthenticationHook(
                    $session,
                    $tpl
                )
            );
            $userAuth = new SimpleAuth(
                $config->getSection('FormAuthentication')->toArray()
            );
            $service->addModule(
                new FormAuthenticationModule(
                    $userAuth,
                    $session,
                    $tpl
                )
            );

            break;
        default:
            throw new RuntimeException('unsupported authentication mechanism');
    }

    // vpn-server-api
    $serverClient = new ServerClient(
        new CurlHttpClient([$config->getItem('apiUser'), $config->getItem('apiPass')]),
        $config->getItem('apiUri')
    );

    $service->addBeforeHook('two_factor', new TwoFactorHook($session, $tpl, $serverClient));

    // two factor module
    $twoFactorModule = new TwoFactorModule($serverClient, $session, $tpl);
    $service->addModule($twoFactorModule);

    $graph = new Graph();
    $graph->setFontList($fontList);
    if ($config->hasSection('statsConfig')) {
        if ($config->getSection('statsConfig')->hasItem('barColor')) {
            $graph->setBarColor($config->getSection('statsConfig')->getItem('barColor'));
        }
    }

    $adminPortalModule = new AdminPortalModule(
        $tpl,
        $serverClient,
        $graph
    );
    $service->addModule($adminPortalModule);

    $service->run($request)->send();
} catch (Exception $e) {
    $logger->error($e->getMessage());
    $response = new HtmlResponse($e->getMessage(), 500);
    $response->send();
}
