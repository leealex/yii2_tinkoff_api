<?php

namespace app\services;

use Exception;
use GuzzleHttp\Client;
use Yii;
use yii\base\BaseObject;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;
use yii\helpers\Json;

/**
 * Class Tinkoff
 * @package Tinkoff
 */
class Tinkoff extends BaseObject
{
    /** @var Client */
    private $client;
    /** @var string */
    private $baseUri = 'https://api.tinkoff.ru';
    /** @var string */
    private $origin = 'web,ib5,platform';
    /** @var string|null */
    public $webUserId = null;
    /** @var string|null */
    public $sessionId = null;
    /** @var bool Нужно ли начать сессию сначала */
    public $newSession = false;
    /** @var string Телефон пользователя */
    public $phone;

    /**
     * @inheritDoc
     */
    public function __construct($config = [])
    {
        Yii::configure($this, $config);

        if (!$this->phone) {
            throw new InvalidConfigException('Phone number required');
        }
        $this->phone = '+7' . ltrim($this->phone, ' +7');

        $this->client = new Client(['base_uri' => $this->baseUri]);

        $path = Yii::getAlias('@runtime/tinkoff/');
        if (!file_exists($path)) {
            FileHelper::createDirectory($path);
        }
        if ($this->newSession) {
            $this->getWebUserId();
            $this->getSessionId();
            file_put_contents($path . $this->phone . '.json', Json::encode([
                'origin' => $this->origin,
                'sessionid' => $this->sessionId,
                'wuid' => $this->webUserId
            ]));
        } else {
            $session = Json::decode(file_get_contents($path . $this->phone . '.json'));
            $this->sessionId = $session['sessionid'];
            $this->webUserId = $session['wuid'];
        }

        parent::__construct($config);
    }

    /**
     * Получение ID веб-пользователя
     *
     * @throws Exception
     */
    public function getWebUserId()
    {
        $data = $this->sendRequest('webuser');
        $this->webUserId = $data->payload->wuid;
    }

    /**
     * Получение ИД сессии
     *
     * @throws Exception
     */
    public function getSessionId()
    {
        $data = $this->sendRequest('session', ['origin' => $this->origin]);
        $this->sessionId = $data->payload;
    }

    /**
     * Проверка состояния сессии
     *
     * @return bool|object|string
     * @throws Exception
     */
    public function getSessionStatus()
    {
        return $this->sendRequest('session_status', [
            'origin' => $this->origin,
            'sessionid' => $this->sessionId
        ]);
    }

    /**
     * Проверка состояния сессии
     *
     * @return bool|object|string
     * @throws Exception
     */
    public function ping()
    {
        return $this->sendRequest('ping', [
            'origin' => $this->origin,
            'sessionid' => $this->sessionId,
            'wuid' => $this->webUserId
        ]);
    }

    /**
     * @param $phone
     * @return bool|object|string
     * @throws Exception
     */
    public function warmUpCache($phone)
    {
        return $this->sendRequest('warmup_cache', [
            'origin' => $this->origin,
            'sessionid' => $this->sessionId,
            'wuid' => $this->webUserId
        ], ['phone' => $phone]);
    }

    /**
     * Подтверждение по SMS
     *
     * @param $ticket
     * @param $operation
     * @param $code
     * @return bool|object|string
     * @throws Exception
     */
    public function confirm($ticket, $operation, $code)
    {
        return $this->sendRequest('confirm', [
            'origin' => $this->origin,
            'sessionid' => $this->sessionId,
            'wuid' => $this->webUserId
        ], [
            'initialOperationTicket' => $ticket,
            'initialOperation' => $operation,
            'confirmationData' => '{"SMSBYID":"' . $code . '"}'
        ]);
    }

    /**
     * Вход в личный кабинет по логину и паролю
     *
     * @param null $phone
     * @param $password
     * @return object
     * @throws Exception
     */
    public function signUp($phone = null, $password = null)
    {
        $params = [
            'wuid' => $this->webUserId,
            'entrypoint_type' => 'context',
            'fingerprint' => 'Mozilla/5.0 (Macintosh Intel Mac OS X 10_15_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36###1920x1080x24###-360###true###true###Chrome PDF Plugin::Portable Document Format::application/x-google-chrome-pdf~pdf;Chrome PDF Viewer::::application/pdf~pdf;Native Client::::application/x-nacl~,application/x-pnacl~',
            'fingerprint_gpu_shading_language_version' => 'WebGL GLSL ES 1.0 (OpenGL ES GLSL ES 1.0 Chromium)',
            'fingerprint_gpu_vendor' => 'WebKit',
            'fingerprint_gpu_extensions_hash' => 'b2dfbb26a11469d9421a31affdc1d44a',
            'fingerprint_gpu_extensions_count' => '28',
            'fingerprint_device_platform' => 'MacIntel',
            'fingerprint_client_timezone' => '-360',
            'fingerprint_client_language' => 'ru-RU',
            'fingerprint_canvas' => '10db268bca4658c570ce1f7d739a308d',
            'fingerprint_accept_language' => 'ru-RU,ru,en-US,en',
            'device_type' => 'desktop',
            'form_view_mode' => 'desktop',
        ];
        if ($phone) {
            $params['phone'] = $phone;
        }
        if ($password) {
            $params['password'] = $password;
        }

        return $this->sendRequest('sign_up', [
            'origin' => $this->origin,
            'sessionid' => $this->sessionId,
            'wuid' => $this->webUserId
        ], $params);
    }

    /**
     * Повышение уровня доступа
     *
     * @return object
     * @throws Exception
     */
    public function levelUp()
    {
        return $this->sendRequest('level_up', [
            'origin' => $this->origin,
            'sessionid' => $this->sessionId,
            'wuid' => $this->webUserId
        ]);
    }

    /**
     * Информация о пользователе
     *
     * @return object
     * @throws Exception
     */
    public function getPersonalInfo()
    {
        return $this->sendRequest('personal_info', [
            'sessionid' => $this->sessionId,
            'wuid' => $this->webUserId,
        ]);
    }

    /**
     * Список счетов
     *
     * @return object
     * @throws Exception
     */
    public function getAccounts()
    {
        return $this->sendRequest('accounts_flat', [
            'sessionid' => $this->sessionId,
            'wuid' => $this->webUserId,
        ]);
    }

    /**
     * Отправка запроса
     *
     * @param $method
     * @param array $query
     * @param array $params
     * @return bool|string|object
     * @throws Exception
     */
    public function sendRequest($method, $query = [], $params = [])
    {
        $methodUri = "/v1/{$method}";

        if ($params) {
            $data = $this->post($methodUri, $query, $params);
        } else {
            $data = $this->get($methodUri, $query);
        }
        return $data;
    }

    /**
     * GET запрос
     *
     * @param $uri
     * @param array $query
     * @return object
     */
    private function get($uri, $query = [])
    {
        $response = $this->client->get($uri, [
            'query' => $query
        ]);

        return json_decode($response->getBody());
    }

    /**
     * POST запрос
     *
     * @param $uri
     * @param array $query
     * @param array $params
     * @return object
     */
    private function post($uri, $query = [], $params = [])
    {
        $response = $this->client->post($uri, [
            'query' => $query,
            'form_params' => $params,
        ]);

        return json_decode($response->getBody());
    }
}
