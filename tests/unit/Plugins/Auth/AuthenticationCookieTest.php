<?php

declare(strict_types=1);

namespace PhpMyAdmin\Tests\Plugins\Auth;

use PhpMyAdmin\Config;
use PhpMyAdmin\Current;
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\Exceptions\AuthenticationFailure;
use PhpMyAdmin\Exceptions\ExitException;
use PhpMyAdmin\Plugins\Auth\AuthenticationCookie;
use PhpMyAdmin\ResponseRenderer;
use PhpMyAdmin\Tests\AbstractTestCase;
use PhpMyAdmin\Tests\Stubs\ResponseRenderer as ResponseRendererStub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

use function base64_decode;
use function base64_encode;
use function is_readable;
use function json_encode;
use function mb_strlen;
use function ob_get_clean;
use function ob_start;
use function random_bytes;
use function str_repeat;
use function str_shuffle;
use function time;

use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

#[CoversClass(AuthenticationCookie::class)]
#[Medium]
class AuthenticationCookieTest extends AbstractTestCase
{
    protected AuthenticationCookie $object;

    /**
     * Configures global environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setLanguage();

        $this->setGlobalConfig();

        DatabaseInterface::$instance = $this->createDatabaseInterface();
        Current::$database = 'db';
        Current::$table = 'table';
        $_POST['pma_password'] = '';
        $this->object = new AuthenticationCookie();
        $_SERVER['PHP_SELF'] = '/phpmyadmin/index.php';
        Config::getInstance()->selectedServer['DisableIS'] = false;
        $GLOBALS['conn_error'] = null;
    }

    /**
     * tearDown for test cases
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        unset($this->object);
    }

    public function testAuthErrorAJAX(): void
    {
        $GLOBALS['conn_error'] = true;

        $responseStub = new ResponseRendererStub();
        $responseStub->setAjax(true);
        (new ReflectionProperty(ResponseRenderer::class, 'instance'))->setValue(null, $responseStub);

        try {
            $this->object->showLoginForm();
        } catch (Throwable $throwable) {
        }

        self::assertInstanceOf(ExitException::class, $throwable);
        $response = $responseStub->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($responseStub->hasSuccessState());
        self::assertSame(['redirect_flag' => '1'], $responseStub->getJSONResult());
    }

    public function testAuthError(): void
    {
        $_REQUEST = [];

        $_REQUEST['old_usr'] = '';
        $config = Config::getInstance();
        $config->settings['LoginCookieRecall'] = true;
        $config->settings['blowfish_secret'] = str_repeat('a', 32);
        $this->object->user = 'pmauser';
        $GLOBALS['pma_auth_server'] = 'localhost';

        $GLOBALS['conn_error'] = true;
        $config->settings['Lang'] = 'en';
        $config->settings['AllowArbitraryServer'] = true;
        $config->settings['CaptchaApi'] = '';
        $config->settings['CaptchaRequestParam'] = '';
        $config->settings['CaptchaResponseParam'] = '';
        $config->settings['CaptchaLoginPrivateKey'] = '';
        $config->settings['CaptchaLoginPublicKey'] = '';
        Current::$database = 'testDb';
        Current::$table = 'testTable';
        $config->settings['Servers'] = [1, 2];

        $responseStub = new ResponseRendererStub();
        (new ReflectionProperty(ResponseRenderer::class, 'instance'))->setValue(null, $responseStub);

        try {
            $this->object->showLoginForm();
        } catch (Throwable $throwable) {
        }

        $result = $responseStub->getHTMLResult();

        self::assertInstanceOf(ExitException::class, $throwable);

        self::assertStringContainsString(' id="imLogo"', $result);

        self::assertStringContainsString('<div class="alert alert-danger" role="alert">', $result);

        self::assertStringContainsString(
            '<form method="post" id="login_form" action="index.php?route=/" name="login_form" ' .
            'class="disableAjax hide js-show">',
            $result,
        );

        self::assertStringContainsString(
            '<input type="text" name="pma_servername" id="serverNameInput" value="localhost"',
            $result,
        );

        self::assertStringContainsString(
            '<input type="text" name="pma_username" id="input_username" ' .
            'value="pmauser" class="form-control" autocomplete="username" spellcheck="false" autofocus>',
            $result,
        );

        self::assertStringContainsString(
            '<input type="password" name="pma_password" id="input_password" ' .
            'value="" class="form-control" autocomplete="current-password" spellcheck="false">',
            $result,
        );

        self::assertStringContainsString(
            '<select name="server" id="select_server" class="form-select" ' .
            'onchange="document.forms[\'login_form\'].' .
            'elements[\'pma_servername\'].value = \'\'">',
            $result,
        );

        self::assertStringContainsString('<input type="hidden" name="db" value="testDb">', $result);

        self::assertStringContainsString('<input type="hidden" name="table" value="testTable">', $result);
    }

    public function testAuthCaptcha(): void
    {
        $_REQUEST['old_usr'] = '';
        $config = Config::getInstance();
        $config->settings['LoginCookieRecall'] = false;

        $config->settings['Lang'] = '';
        $config->settings['AllowArbitraryServer'] = false;
        $config->settings['Servers'] = [1];
        $config->settings['CaptchaApi'] = 'https://www.google.com/recaptcha/api.js';
        $config->settings['CaptchaRequestParam'] = 'g-recaptcha';
        $config->settings['CaptchaResponseParam'] = 'g-recaptcha-response';
        $config->settings['CaptchaLoginPrivateKey'] = 'testprivkey';
        $config->settings['CaptchaLoginPublicKey'] = 'testpubkey';
        Current::$server = 2;

        $responseStub = new ResponseRendererStub();
        (new ReflectionProperty(ResponseRenderer::class, 'instance'))->setValue(null, $responseStub);

        try {
            $this->object->showLoginForm();
        } catch (Throwable $throwable) {
        }

        $result = $responseStub->getHTMLResult();

        self::assertInstanceOf(ExitException::class, $throwable);

        self::assertStringContainsString('id="imLogo"', $result);

        // Check for language selection if locales are there
        $loc = LOCALE_PATH . '/cs/LC_MESSAGES/phpmyadmin.mo';
        if (is_readable($loc)) {
            self::assertStringContainsString(
                '<select name="lang" class="form-select autosubmit" lang="en" dir="ltr"'
                . ' id="languageSelect" aria-labelledby="languageSelectLabel">',
                $result,
            );
        }

        self::assertStringContainsString(
            '<form method="post" id="login_form" action="index.php?route=/" name="login_form"' .
            ' class="disableAjax hide js-show" autocomplete="off">',
            $result,
        );

        self::assertStringContainsString('<input type="hidden" name="server" value="2">', $result);

        self::assertStringContainsString(
            '<script src="https://www.google.com/recaptcha/api.js?hl=en" async defer></script>',
            $result,
        );

        self::assertStringContainsString(
            '<input class="btn btn-primary g-recaptcha" data-sitekey="testpubkey"'
            . ' data-callback="recaptchaCallback" value="Log in" type="submit" id="input_go">',
            $result,
        );
    }

    public function testAuthCaptchaCheckbox(): void
    {
        $_REQUEST['old_usr'] = '';
        $config = Config::getInstance();
        $config->settings['LoginCookieRecall'] = false;

        $config->settings['Lang'] = '';
        $config->settings['AllowArbitraryServer'] = false;
        $config->settings['Servers'] = [1];
        $config->settings['CaptchaApi'] = 'https://www.google.com/recaptcha/api.js';
        $config->settings['CaptchaRequestParam'] = 'g-recaptcha';
        $config->settings['CaptchaResponseParam'] = 'g-recaptcha-response';
        $config->settings['CaptchaLoginPrivateKey'] = 'testprivkey';
        $config->settings['CaptchaLoginPublicKey'] = 'testpubkey';
        $config->settings['CaptchaMethod'] = 'checkbox';
        Current::$server = 2;

        $responseStub = new ResponseRendererStub();
        (new ReflectionProperty(ResponseRenderer::class, 'instance'))->setValue(null, $responseStub);

        try {
            $this->object->showLoginForm();
        } catch (Throwable $throwable) {
        }

        $result = $responseStub->getHTMLResult();

        self::assertInstanceOf(ExitException::class, $throwable);

        self::assertStringContainsString('id="imLogo"', $result);

        // Check for language selection if locales are there
        $loc = LOCALE_PATH . '/cs/LC_MESSAGES/phpmyadmin.mo';
        if (is_readable($loc)) {
            self::assertStringContainsString(
                '<select name="lang" class="form-select autosubmit" lang="en" dir="ltr"'
                . ' id="languageSelect" aria-labelledby="languageSelectLabel">',
                $result,
            );
        }

        self::assertStringContainsString(
            '<form method="post" id="login_form" action="index.php?route=/" name="login_form"' .
            ' class="disableAjax hide js-show" autocomplete="off">',
            $result,
        );

        self::assertStringContainsString('<input type="hidden" name="server" value="2">', $result);

        self::assertStringContainsString(
            '<script src="https://www.google.com/recaptcha/api.js?hl=en" async defer></script>',
            $result,
        );

        self::assertStringContainsString('<div class="g-recaptcha" data-sitekey="testpubkey"></div>', $result);

        self::assertStringContainsString(
            '<input class="btn btn-primary" value="Log in" type="submit" id="input_go">',
            $result,
        );
    }

    public function testAuthHeader(): void
    {
        $config = Config::getInstance();
        $config->settings['LoginCookieDeleteAll'] = false;
        $config->settings['Servers'] = [1];

        $responseStub = new ResponseRendererStub();
        (new ReflectionProperty(ResponseRenderer::class, 'instance'))->setValue(null, $responseStub);

        $config->selectedServer['LogoutURL'] = 'https://example.com/logout';
        $config->selectedServer['auth_type'] = 'cookie';

        $this->object->logOut();

        $response = $responseStub->getResponse();
        self::assertSame(['https://example.com/logout'], $response->getHeader('Location'));
        self::assertSame(302, $response->getStatusCode());
    }

    public function testAuthHeaderPartial(): void
    {
        $config = Config::getInstance();
        $config->set('is_https', false);
        $config->settings['LoginCookieDeleteAll'] = false;
        $config->settings['Servers'] = [1, 2, 3];
        $config->selectedServer['LogoutURL'] = 'https://example.com/logout';
        $config->selectedServer['auth_type'] = 'cookie';

        $_COOKIE['pmaAuth-2'] = '';

        $responseStub = new ResponseRendererStub();
        (new ReflectionProperty(ResponseRenderer::class, 'instance'))->setValue(null, $responseStub);

        $this->object->logOut();

        $response = $responseStub->getResponse();
        self::assertSame(['/phpmyadmin/index.php?route=/&server=2&lang=en'], $response->getHeader('Location'));
        self::assertSame(302, $response->getStatusCode());
    }

    public function testAuthCheckCaptcha(): void
    {
        $config = Config::getInstance();
        $config->settings['CaptchaApi'] = 'https://www.google.com/recaptcha/api.js';
        $config->settings['CaptchaRequestParam'] = 'g-recaptcha';
        $config->settings['CaptchaResponseParam'] = 'g-recaptcha-response';
        $config->settings['CaptchaLoginPrivateKey'] = 'testprivkey';
        $config->settings['CaptchaLoginPublicKey'] = 'testpubkey';
        $_POST['g-recaptcha-response'] = '';
        $_POST['pma_username'] = 'testPMAUser';

        self::assertFalse(
            $this->object->readCredentials(),
        );

        self::assertSame('Missing Captcha verification, maybe it has been blocked by adblock?', $GLOBALS['conn_error']);
    }

    public function testLogoutDelete(): void
    {
        $responseStub = new ResponseRendererStub();
        (new ReflectionProperty(ResponseRenderer::class, 'instance'))->setValue(null, $responseStub);

        $config = Config::getInstance();
        $config->settings['CaptchaApi'] = '';
        $config->settings['CaptchaRequestParam'] = '';
        $config->settings['CaptchaResponseParam'] = '';
        $config->settings['CaptchaLoginPrivateKey'] = '';
        $config->settings['CaptchaLoginPublicKey'] = '';
        $config->settings['LoginCookieDeleteAll'] = true;
        $config->set('PmaAbsoluteUri', '');
        $config->set('is_https', false);
        $config->settings['Servers'] = [1];

        $_COOKIE['pmaAuth-0'] = 'test';

        $this->object->logOut();

        $response = $responseStub->getResponse();
        self::assertSame(['/phpmyadmin/index.php?route=/'], $response->getHeader('Location'));
        self::assertSame(302, $response->getStatusCode());

        self::assertArrayNotHasKey('pmaAuth-0', $_COOKIE);
    }

    public function testLogout(): void
    {
        $responseStub = new ResponseRendererStub();
        (new ReflectionProperty(ResponseRenderer::class, 'instance'))->setValue(null, $responseStub);

        $config = Config::getInstance();
        $config->settings['CaptchaApi'] = '';
        $config->settings['CaptchaRequestParam'] = '';
        $config->settings['CaptchaResponseParam'] = '';
        $config->settings['CaptchaLoginPrivateKey'] = '';
        $config->settings['CaptchaLoginPublicKey'] = '';
        $config->settings['LoginCookieDeleteAll'] = false;
        $config->set('PmaAbsoluteUri', '');
        $config->set('is_https', false);
        $config->settings['Servers'] = [1];
        $config->selectedServer = ['auth_type' => 'cookie'];

        $_COOKIE['pmaAuth-1'] = 'test';

        $this->object->logOut();

        $response = $responseStub->getResponse();
        self::assertSame(['/phpmyadmin/index.php?route=/'], $response->getHeader('Location'));
        self::assertSame(302, $response->getStatusCode());

        self::assertArrayNotHasKey('pmaAuth-1', $_COOKIE);
    }

    public function testAuthCheckArbitrary(): void
    {
        $config = Config::getInstance();
        $config->settings['CaptchaApi'] = '';
        $config->settings['CaptchaRequestParam'] = '';
        $config->settings['CaptchaResponseParam'] = '';
        $config->settings['CaptchaLoginPrivateKey'] = '';
        $config->settings['CaptchaLoginPublicKey'] = '';
        $_REQUEST['old_usr'] = '';
        $_POST['pma_username'] = 'testPMAUser';
        $_REQUEST['pma_servername'] = 'testPMAServer';
        $_POST['pma_password'] = 'testPMAPSWD';
        $config->settings['AllowArbitraryServer'] = true;

        self::assertTrue(
            $this->object->readCredentials(),
        );

        self::assertSame('testPMAUser', $this->object->user);

        self::assertSame('testPMAPSWD', $this->object->password);

        self::assertSame('testPMAServer', $GLOBALS['pma_auth_server']);

        self::assertArrayNotHasKey('pmaAuth-1', $_COOKIE);
    }

    public function testAuthCheckInvalidCookie(): void
    {
        Config::getInstance()->settings['AllowArbitraryServer'] = true;
        $_REQUEST['pma_servername'] = 'testPMAServer';
        $_POST['pma_password'] = 'testPMAPSWD';
        $_POST['pma_username'] = '';
        $_COOKIE['pmaUser-1'] = '';
        $_COOKIE['pma_iv-1'] = base64_encode('testiv09testiv09');

        self::assertFalse(
            $this->object->readCredentials(),
        );
    }

    public function testAuthCheckExpires(): void
    {
        $_COOKIE['pmaServer-1'] = 'pmaServ1';
        $_COOKIE['pmaUser-1'] = 'pmaUser1';
        $_COOKIE['pma_iv-1'] = base64_encode('testiv09testiv09');
        $_COOKIE['pmaAuth-1'] = '';
        $config = Config::getInstance();
        $config->settings['blowfish_secret'] = str_repeat('a', 32);
        $_SESSION['last_access_time'] = time() - 1000;
        $config->settings['LoginCookieValidity'] = 1440;

        self::assertFalse(
            $this->object->readCredentials(),
        );
    }

    public function testAuthCheckDecryptUser(): void
    {
        $_REQUEST['old_usr'] = '';
        $_POST['pma_username'] = '';
        $_COOKIE['pmaServer-1'] = 'pmaServ1';
        $_COOKIE['pmaUser-1'] = 'pmaUser1';
        $_COOKIE['pma_iv-1'] = base64_encode('testiv09testiv09');
        $config = Config::getInstance();
        $config->settings['blowfish_secret'] = str_repeat('a', 32);
        $_SESSION['last_access_time'] = '';
        $config->settings['CaptchaApi'] = '';
        $config->settings['CaptchaRequestParam'] = '';
        $config->settings['CaptchaResponseParam'] = '';
        $config->settings['CaptchaLoginPrivateKey'] = '';
        $config->settings['CaptchaLoginPublicKey'] = '';
        $config->set('is_https', false);

        // mock for blowfish function
        $this->object = $this->getMockBuilder(AuthenticationCookie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['cookieDecrypt'])
            ->getMock();

        $this->object->expects(self::once())
            ->method('cookieDecrypt')
            ->willReturn('testBF');

        self::assertFalse(
            $this->object->readCredentials(),
        );

        self::assertSame('testBF', $this->object->user);
    }

    public function testAuthCheckDecryptPassword(): void
    {
        $_REQUEST['old_usr'] = '';
        $_POST['pma_username'] = '';
        $_COOKIE['pmaServer-1'] = 'pmaServ1';
        $_COOKIE['pmaUser-1'] = 'pmaUser1';
        $_COOKIE['pmaAuth-1'] = 'pmaAuth1';
        $_COOKIE['pma_iv-1'] = base64_encode('testiv09testiv09');
        $config = Config::getInstance();
        $config->settings['blowfish_secret'] = str_repeat('a', 32);
        $config->settings['CaptchaApi'] = '';
        $config->settings['CaptchaRequestParam'] = '';
        $config->settings['CaptchaResponseParam'] = '';
        $config->settings['CaptchaLoginPrivateKey'] = '';
        $config->settings['CaptchaLoginPublicKey'] = '';
        $_SESSION['browser_access_time']['default'] = time() - 1000;
        $config->settings['LoginCookieValidity'] = 1440;
        $config->set('is_https', false);

        // mock for blowfish function
        $this->object = $this->getMockBuilder(AuthenticationCookie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['cookieDecrypt'])
            ->getMock();

        $this->object->expects(self::exactly(2))
            ->method('cookieDecrypt')
            ->willReturn('{"password":""}');

        self::assertTrue(
            $this->object->readCredentials(),
        );

        self::assertTrue($GLOBALS['from_cookie']);

        self::assertSame('', $this->object->password);
    }

    public function testAuthCheckAuthFails(): void
    {
        $_REQUEST['old_usr'] = '';
        $_POST['pma_username'] = '';
        $_COOKIE['pmaServer-1'] = 'pmaServ1';
        $_COOKIE['pmaUser-1'] = 'pmaUser1';
        $_COOKIE['pma_iv-1'] = base64_encode('testiv09testiv09');
        $config = Config::getInstance();
        $config->settings['blowfish_secret'] = str_repeat('a', 32);
        $_SESSION['last_access_time'] = 1;
        $config->settings['CaptchaApi'] = '';
        $config->settings['CaptchaRequestParam'] = '';
        $config->settings['CaptchaResponseParam'] = '';
        $config->settings['CaptchaLoginPrivateKey'] = '';
        $config->settings['CaptchaLoginPublicKey'] = '';
        $config->settings['LoginCookieValidity'] = 0;
        $_SESSION['browser_access_time']['default'] = -1;
        $config->set('is_https', false);

        // mock for blowfish function
        $this->object = $this->getMockBuilder(AuthenticationCookie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['showFailure', 'cookieDecrypt'])
            ->getMock();

        $this->object->expects(self::once())
            ->method('cookieDecrypt')
            ->willReturn('testBF');

        $this->expectExceptionObject(AuthenticationFailure::noActivity());
        $this->object->readCredentials();
    }

    public function testAuthSetUser(): void
    {
        $this->object->user = 'pmaUser2';
        $arr = ['host' => 'a', 'port' => 1, 'socket' => true, 'ssl' => true, 'user' => 'pmaUser2'];

        $config = Config::getInstance();
        $config->selectedServer = $arr;
        $config->selectedServer['user'] = 'pmaUser';
        $config->settings['Servers'][1] = $arr;
        $config->settings['AllowArbitraryServer'] = true;
        $GLOBALS['pma_auth_server'] = 'b 2';
        $this->object->password = 'testPW';
        Current::$server = 2;
        $config->settings['LoginCookieStore'] = 100;
        $GLOBALS['from_cookie'] = true;
        $config->set('is_https', false);

        $this->object->storeCredentials();

        $this->object->rememberCredentials();

        self::assertArrayHasKey('pmaUser-2', $_COOKIE);

        self::assertArrayHasKey('pmaAuth-2', $_COOKIE);

        $arr['password'] = 'testPW';
        $arr['host'] = 'b';
        $arr['port'] = '2';
        self::assertSame($arr, $config->selectedServer);
    }

    public function testAuthSetUserWithHeaders(): void
    {
        $this->object->user = 'pmaUser2';
        $arr = ['host' => 'a', 'port' => 1, 'socket' => true, 'ssl' => true, 'user' => 'pmaUser2'];

        $config = Config::getInstance();
        $config->selectedServer = $arr;
        $config->selectedServer['host'] = 'b';
        $config->selectedServer['user'] = 'pmaUser';
        $config->settings['Servers'][1] = $arr;
        $config->settings['AllowArbitraryServer'] = true;
        $GLOBALS['pma_auth_server'] = 'b 2';
        $this->object->password = 'testPW';
        $config->settings['LoginCookieStore'] = 100;
        $GLOBALS['from_cookie'] = false;

        $responseStub = new ResponseRendererStub();
        (new ReflectionProperty(ResponseRenderer::class, 'instance'))->setValue(null, $responseStub);

        $this->object->storeCredentials();
        $this->expectException(ExitException::class);
        $this->object->rememberCredentials();
    }

    public function testAuthFailsNoPass(): void
    {
        $this->object = $this->getMockBuilder(AuthenticationCookie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['showLoginForm'])
            ->getMock();

        $this->object->expects(self::exactly(1))
            ->method('showLoginForm')
            ->willThrowException(new ExitException());

        $_COOKIE['pmaAuth-2'] = 'pass';

        $responseStub = new ResponseRendererStub();
        (new ReflectionProperty(ResponseRenderer::class, 'instance'))->setValue(null, $responseStub);

        try {
            $this->object->showFailure(AuthenticationFailure::emptyDenied());
        } catch (Throwable $throwable) {
        }

        self::assertInstanceOf(ExitException::class, $throwable);
        $response = $responseStub->getResponse();
        self::assertSame(['no-store, no-cache, must-revalidate'], $response->getHeader('Cache-Control'));
        self::assertSame(['no-cache'], $response->getHeader('Pragma'));
        self::assertSame(200, $response->getStatusCode());

        self::assertSame(
            $GLOBALS['conn_error'],
            'Login without a password is forbidden by configuration (see AllowNoPassword).',
        );
    }

    /** @return mixed[] */
    public static function dataProviderPasswordLength(): array
    {
        return [
            [
                str_repeat('a', 2001),
                false,
                'Your password is too long. To prevent denial-of-service attacks,'
                . ' phpMyAdmin restricts passwords to less than 2000 characters.',
            ],
            [
                str_repeat('a', 3000),
                false,
                'Your password is too long. To prevent denial-of-service attacks,'
                . ' phpMyAdmin restricts passwords to less than 2000 characters.',
            ],
            [str_repeat('a', 256), true, null],
            ['', true, null],
        ];
    }

    #[DataProvider('dataProviderPasswordLength')]
    public function testAuthFailsTooLongPass(string $password, bool $expected, string|null $connError): void
    {
        $_POST['pma_username'] = str_shuffle('123456987rootfoobar');
        $_POST['pma_password'] = $password;

        self::assertSame(
            $expected,
            $this->object->readCredentials(),
        );

        self::assertSame($GLOBALS['conn_error'], $connError);
    }

    public function testAuthFailsDeny(): void
    {
        $this->object = $this->getMockBuilder(AuthenticationCookie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['showLoginForm'])
            ->getMock();

        $this->object->expects(self::exactly(1))
            ->method('showLoginForm')
            ->willThrowException(new ExitException());

        $_COOKIE['pmaAuth-2'] = 'pass';

        $responseStub = new ResponseRendererStub();
        (new ReflectionProperty(ResponseRenderer::class, 'instance'))->setValue(null, $responseStub);

        try {
            $this->object->showFailure(AuthenticationFailure::allowDenied());
        } catch (Throwable $throwable) {
        }

        self::assertInstanceOf(ExitException::class, $throwable);
        $response = $responseStub->getResponse();
        self::assertSame(['no-store, no-cache, must-revalidate'], $response->getHeader('Cache-Control'));
        self::assertSame(['no-cache'], $response->getHeader('Pragma'));
        self::assertSame(200, $response->getStatusCode());

        self::assertSame($GLOBALS['conn_error'], 'Access denied!');
    }

    public function testAuthFailsActivity(): void
    {
        $this->object = $this->getMockBuilder(AuthenticationCookie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['showLoginForm'])
            ->getMock();

        $this->object->expects(self::exactly(1))
            ->method('showLoginForm')
            ->willThrowException(new ExitException());

        $_COOKIE['pmaAuth-2'] = 'pass';

        Config::getInstance()->settings['LoginCookieValidity'] = 10;

        $responseStub = new ResponseRendererStub();
        (new ReflectionProperty(ResponseRenderer::class, 'instance'))->setValue(null, $responseStub);

        try {
            $this->object->showFailure(AuthenticationFailure::noActivity());
        } catch (Throwable $throwable) {
        }

        self::assertInstanceOf(ExitException::class, $throwable);
        $response = $responseStub->getResponse();
        self::assertSame(['no-store, no-cache, must-revalidate'], $response->getHeader('Cache-Control'));
        self::assertSame(['no-cache'], $response->getHeader('Pragma'));
        self::assertSame(200, $response->getStatusCode());

        self::assertSame(
            $GLOBALS['conn_error'],
            'You have been automatically logged out due to inactivity of 10 seconds.'
            . ' Once you log in again, you should be able to resume the work where you left off.',
        );
    }

    public function testAuthFailsDBI(): void
    {
        $this->object = $this->getMockBuilder(AuthenticationCookie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['showLoginForm'])
            ->getMock();

        $this->object->expects(self::exactly(1))
            ->method('showLoginForm')
            ->willThrowException(new ExitException());

        $_COOKIE['pmaAuth-2'] = 'pass';

        $dbi = $this->getMockBuilder(DatabaseInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects(self::once())
            ->method('getError')
            ->willReturn('');

        DatabaseInterface::$instance = $dbi;
        $GLOBALS['errno'] = 42;

        $responseStub = new ResponseRendererStub();
        (new ReflectionProperty(ResponseRenderer::class, 'instance'))->setValue(null, $responseStub);

        try {
            $this->object->showFailure(AuthenticationFailure::serverDenied());
        } catch (Throwable $throwable) {
        }

        self::assertInstanceOf(ExitException::class, $throwable);
        $response = $responseStub->getResponse();
        self::assertSame(['no-store, no-cache, must-revalidate'], $response->getHeader('Cache-Control'));
        self::assertSame(['no-cache'], $response->getHeader('Pragma'));
        self::assertSame(200, $response->getStatusCode());

        self::assertSame($GLOBALS['conn_error'], '#42 Cannot log in to the database server.');
    }

    public function testAuthFailsErrno(): void
    {
        $this->object = $this->getMockBuilder(AuthenticationCookie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['showLoginForm'])
            ->getMock();

        $this->object->expects(self::exactly(1))
            ->method('showLoginForm')
            ->willThrowException(new ExitException());

        $dbi = $this->getMockBuilder(DatabaseInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects(self::once())
            ->method('getError')
            ->willReturn('');

        DatabaseInterface::$instance = $dbi;
        $_COOKIE['pmaAuth-2'] = 'pass';

        unset($GLOBALS['errno']);

        $responseStub = new ResponseRendererStub();
        (new ReflectionProperty(ResponseRenderer::class, 'instance'))->setValue(null, $responseStub);

        try {
            $this->object->showFailure(AuthenticationFailure::serverDenied());
        } catch (Throwable $throwable) {
        }

        self::assertInstanceOf(ExitException::class, $throwable);
        $response = $responseStub->getResponse();
        self::assertSame(['no-store, no-cache, must-revalidate'], $response->getHeader('Cache-Control'));
        self::assertSame(['no-cache'], $response->getHeader('Pragma'));
        self::assertSame(200, $response->getStatusCode());

        self::assertSame($GLOBALS['conn_error'], 'Cannot log in to the database server.');
    }

    public function testGetEncryptionSecretEmpty(): void
    {
        $method = new ReflectionMethod(AuthenticationCookie::class, 'getEncryptionSecret');

        Config::getInstance()->settings['blowfish_secret'] = '';
        $_SESSION['encryption_key'] = '';

        $result = $method->invoke($this->object, null);

        self::assertSame($result, $_SESSION['encryption_key']);
        self::assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, mb_strlen($result, '8bit'));
    }

    public function testGetEncryptionSecretConfigured(): void
    {
        $method = new ReflectionMethod(AuthenticationCookie::class, 'getEncryptionSecret');

        $key = str_repeat('a', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        Config::getInstance()->settings['blowfish_secret'] = $key;
        $_SESSION['encryption_key'] = '';

        $result = $method->invoke($this->object, null);

        self::assertSame($key, $result);
    }

    public function testGetSessionEncryptionSecretConfigured(): void
    {
        $method = new ReflectionMethod(AuthenticationCookie::class, 'getEncryptionSecret');

        $key = str_repeat('a', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        Config::getInstance()->settings['blowfish_secret'] = 'blowfish_secret';
        $_SESSION['encryption_key'] = $key;

        $result = $method->invoke($this->object, null);

        self::assertSame($key, $result);
    }

    public function testCookieEncryption(): void
    {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $encrypted = $this->object->cookieEncrypt('data123', $key);
        self::assertNotFalse(base64_decode($encrypted, true));
        self::assertSame('data123', $this->object->cookieDecrypt($encrypted, $key));
    }

    public function testCookieDecryptInvalid(): void
    {
        self::assertNull($this->object->cookieDecrypt('', ''));

        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $encrypted = $this->object->cookieEncrypt('data123', $key);
        self::assertSame('data123', $this->object->cookieDecrypt($encrypted, $key));

        self::assertNull($this->object->cookieDecrypt('', $key));
        self::assertNull($this->object->cookieDecrypt($encrypted, ''));
        self::assertNull($this->object->cookieDecrypt($encrypted, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    }

    /** @throws ReflectionException */
    public function testPasswordChange(): void
    {
        $newPassword = 'PMAPASSWD2';
        $config = Config::getInstance();
        $config->set('is_https', false);
        $config->settings['AllowArbitraryServer'] = true;
        $GLOBALS['pma_auth_server'] = 'b 2';
        $_SESSION['encryption_key'] = '';
        $_COOKIE = [];

        $this->object->handlePasswordChange($newPassword);

        $payload = ['password' => $newPassword, 'server' => 'b 2'];

        /** @psalm-suppress EmptyArrayAccess */
        self::assertIsString($_COOKIE['pmaAuth-' . Current::$server]);
        $decryptedCookie = $this->object->cookieDecrypt(
            $_COOKIE['pmaAuth-' . Current::$server],
            $_SESSION['encryption_key'],
        );
        self::assertSame(json_encode($payload), $decryptedCookie);
    }

    public function testAuthenticate(): void
    {
        $config = Config::getInstance();
        $config->settings['CaptchaApi'] = '';
        $config->settings['CaptchaRequestParam'] = '';
        $config->settings['CaptchaResponseParam'] = '';
        $config->settings['CaptchaLoginPrivateKey'] = '';
        $config->settings['CaptchaLoginPublicKey'] = '';
        $config->selectedServer['AllowRoot'] = false;
        $config->selectedServer['AllowNoPassword'] = false;
        $_REQUEST['old_usr'] = '';
        $_POST['pma_username'] = 'testUser';
        $_POST['pma_password'] = 'testPassword';

        ob_start();
        $this->object->authenticate();
        $result = ob_get_clean();

        /* Nothing should be printed */
        self::assertSame('', $result);

        /* Verify readCredentials worked */
        self::assertSame('testUser', $this->object->user);
        self::assertSame('testPassword', $this->object->password);

        /* Verify storeCredentials worked */
        self::assertSame('testUser', $config->selectedServer['user']);
        self::assertSame('testPassword', $config->selectedServer['password']);
    }

    /**
     * @param string  $user     user
     * @param string  $pass     pass
     * @param string  $ip       ip
     * @param bool    $root     root
     * @param bool    $nopass   nopass
     * @param mixed[] $rules    rules
     * @param string  $expected expected result
     */
    #[DataProvider('checkRulesProvider')]
    public function testCheckRules(
        string $user,
        string $pass,
        string $ip,
        bool $root,
        bool $nopass,
        array $rules,
        string $expected,
    ): void {
        $this->object->user = $user;
        $this->object->password = $pass;
        $this->object->storeCredentials();

        $_SERVER['REMOTE_ADDR'] = $ip;

        $config = Config::getInstance();
        $config->selectedServer['AllowRoot'] = $root;
        $config->selectedServer['AllowNoPassword'] = $nopass;
        $config->selectedServer['AllowDeny'] = $rules;

        $exception = null;
        try {
            $this->object->checkRules();
        } catch (AuthenticationFailure $exception) {
        }

        if ($expected === '') {
            self::assertNull($exception, 'checkRules() should not throw an exception.');

            return;
        }

        self::assertInstanceOf(AuthenticationFailure::class, $exception);
        self::assertSame($expected, $exception->failureType);
    }

    /** @return mixed[] */
    public static function checkRulesProvider(): array
    {
        return [
            'nopass-ok' => ['testUser', '', '1.2.3.4', true, true, [], ''],
            'nopass' => ['testUser', '', '1.2.3.4', true, false, [], AuthenticationFailure::EMPTY_DENIED],
            'root-ok' => ['root', 'root', '1.2.3.4', true, true, [], ''],
            'root' => ['root', 'root', '1.2.3.4', false, true, [], AuthenticationFailure::ROOT_DENIED],
            'rules-deny-allow-ok' => [
                'root',
                'root',
                '1.2.3.4',
                true,
                true,
                ['order' => 'deny,allow', 'rules' => ['allow root 1.2.3.4', 'deny % from all']],
                '',
            ],
            'rules-deny-allow-reject' => [
                'user',
                'root',
                '1.2.3.4',
                true,
                true,
                ['order' => 'deny,allow', 'rules' => ['allow root 1.2.3.4', 'deny % from all']],
                AuthenticationFailure::ALLOW_DENIED,
            ],
            'rules-allow-deny-ok' => [
                'root',
                'root',
                '1.2.3.4',
                true,
                true,
                ['order' => 'allow,deny', 'rules' => ['deny user from all', 'allow root 1.2.3.4']],
                '',
            ],
            'rules-allow-deny-reject' => [
                'user',
                'root',
                '1.2.3.4',
                true,
                true,
                ['order' => 'allow,deny', 'rules' => ['deny user from all', 'allow root 1.2.3.4']],
                AuthenticationFailure::ALLOW_DENIED,
            ],
            'rules-explicit-ok' => [
                'root',
                'root',
                '1.2.3.4',
                true,
                true,
                ['order' => 'explicit', 'rules' => ['deny user from all', 'allow root 1.2.3.4']],
                '',
            ],
            'rules-explicit-reject' => [
                'user',
                'root',
                '1.2.3.4',
                true,
                true,
                ['order' => 'explicit', 'rules' => ['deny user from all', 'allow root 1.2.3.4']],
                AuthenticationFailure::ALLOW_DENIED,
            ],
        ];
    }
}
