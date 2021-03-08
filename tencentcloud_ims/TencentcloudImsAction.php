<?php

/*
 * Copyright (C) 2020 Tencent Cloud.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

defined('_JEXEC') or die;

require_once 'DebugerLog.php';
require_once 'vendor/autoload.php';


use TencentCloud\Cms\V20190321\Models\ImageModerationResponse;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Cms\V20190321\CmsClient;
use TencentCloud\Cms\V20190321\Models\ImageModerationRequest;


class TencentcloudImsAction
{
    /**
     * db
     * @var JDatabaseDriver|null
     */
    private $db;

    /**
     * 插件商
     * @var string
     */
    private $name = 'tencentcloud';

    /**
     * 上报url
     * @var string
     */
    private $log_server_url = 'https://appdata.qq.com/upload';

    /**
     * 应用名称
     * @var string
     */
    private $site_app = 'Joomla';

    /**
     * 插件类型
     * @var string
     */
    private $plugin_type = 'ims';

    /**
     * 图片类型
     * @var string
     */
    private $image_type = [
        'image/png',
        'image/jpeg',
        'image/jpg',
        'image/gif',
        'image/bmp',
        'application/octet-stream',
        ];
    private $secret_id;
    private $secret_key;


    /**
     * tencent_cos constructor.
     * @param $cos_options
     */
    public function __construct()
    {
        $this->db = JFactory::getDbo();
        $this->init();
    }

    /**
     * 初始化配置
     */
    private function init()
    {
        $ims_options = $this->getOptions();
        $ims_options = !empty($ims_options) ? $ims_options : array();
        $this->secret_id = isset($ims_options['secret_id']) ? $ims_options['secret_id'] : '';
        $this->secret_key = isset($ims_options['secret_key']) ? $ims_options['secret_key'] : '';
        return true;
    }

    /**
     * 获取腾讯云对象存储插件的用户密钥
     * @return array|bool   用户密钥
     */
    public function getOptions()
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(array('params', 'type')))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('name') . " = 'plg_content_tencentcloud_ims'");
        $db->setQuery($query);

        $params = $db->loadAssoc();
        if (empty($params) || !isset($params, $params['params'])) {
            return false;
        }
        return json_decode($params['params'], true);
    }

    /**
     * 返回cos对象
     * @param array $options 用户自定义插件参数
     * @return \Qcloud\Cos\Client
     */
    private function getClient()
    {
        if (empty($this->secret_id) || empty($this->secret_key)) {
            DebugLog::writeDebugLog('debug', JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_CONF_EMPTY'));
            return false;
        }
        $cred = new Credential($this->secret_id, $this->secret_key);
        $clientProfile = new ClientProfile();
        return new CmsClient($cred, "ap-shanghai", $clientProfile);
    }

    /**
     * 检测在媒体库上传的图片
     * @param $file
     * @return bool
     * @throws Exception
     */
    public function AuditImageInMedia($article)
    {
        if (!is_object($article)) {
            DebugLog::writeDebugLog('debug', JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_FILE_OBJECT_ERROR'));
            return false;
        }
        // 非图片文件，不进行图片安全检查
        if (empty($article->type) || !in_array($article->type, $this->image_type)) {
            DebugLog::writeDebugLog('debug', JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_NOT_IMAGE_FILE'));
            return true;
        }

        $img_content = file_get_contents($article->tmp_name);
        if (!$img_content && strlen($img_content) === 0) {
            DebugLog::writeDebugLog('debug', JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_FILE_EMPTY'));
            $article->setError(JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_FILE_EMPTY'));
            return false;
        }
        self::init();
        return $this->imageModeration($article, $img_content);
    }

    /**
     * 腾讯云图片检测
     * @param $IMSOptions
     * @param string $imgContent 图片内容
     * @return Exception|ImageModerationResponse|TencentCloudSDKException
     * @throws Exception
     */
    private function imageModeration($article, $imgContent = '')
    {
        try {
            if (empty($imgContent)) {
                DebugLog::writeDebugLog('debug', JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_FUNCTION_PARAMS_ERROR'));
                $article->setError(JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_FILE_EMPTY'));
                return false;
            }

            $client = $this->getClient();
            if (!($client instanceof CmsClient)) {
                DebugLog::writeDebugLog('debug', JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_CMSCLIENT_ERROR'));
                return false;
            }

            $req = new ImageModerationRequest();
            $params['FileContent'] = base64_encode($imgContent);

            $req->fromJsonString(json_encode($params, JSON_UNESCAPED_UNICODE));
            $response = $client->ImageModeration($req);
            //腾讯云图片内容安全检测接口返回异常，配置参数错误或服务异常
            if (!($response instanceof ImageModerationResponse)) {
                DebugLog::writeDebugLog('debug', JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_INTERFACE_ERROR'));
                $article->setError(JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_INTERFACE_ERROR'));
                return false;
            }

            if ($response->getData()->EvilFlag === 0 || $response->getData()->EvilType === 100) {
                DebugLog::writeDebugLog('debug', $article->name . JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_PASS'));
                return true;
            }
            DebugLog::writeDebugLog('debug', $article->name . JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_NOT_PASS'));
            $article->setError($article->name . JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_NOT_PASS'));

            return false;
        } catch (TencentCloudSDKException $e) {
            DebugLog::writeDebugLog('debug', JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_FUNCTIONT_EXECEPTION'));
            return false;
        }
    }

    /**
     * 获取腾讯云配置
     */
    public function getSiteInfo()
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['site_id', 'site_url', 'uin']))
            ->from($db->quoteName('#__tencentcloud_conf'))
            ->where('1=1 limit 1');
        $db->setQuery($query);

        try {
            $row = $db->loadAssoc();
        } catch (RuntimeException $e) {
            return false;
        }

        return $row;
    }

    /**
     * 写入腾讯云配置
     */
    public function setSiteInfo()
    {
        $name = $this->name;
        $siteId = uniqid('joomla_');
        $siteUrl = $_SERVER['HTTP_HOST'];
        if (isset($_SERVER["REQUEST_SCHEME"])) {
            $siteUrl = $_SERVER["REQUEST_SCHEME"] . '://' . $siteUrl;
        }

        $db = $this->db;
        $query = $db->getQuery(true);
        $query->insert($db->quoteName('#__tencentcloud_conf'))
            ->columns(array($db->quoteName('name'), $db->quoteName('site_id'), $db->quoteName('site_url')))
            ->values($db->quote($name) . ', ' . $db->quote($siteId) . ', ' . $db->quote($siteUrl));
        $db->setQuery($query);

        try {
            $db->execute();
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /**
     * 判断表是否存在
     * @param string $table_name
     * @return bool
     */
    public function isTableExist($table_name)
    {
        $db = $this->db;
        $table = $db->replacePrefix($db->quoteName($table_name));
        $table = trim($table, "`");
        $tables = $db->getTableList();
        if (in_array($table, $tables)) {
            return true;
        }
        return false;
    }

    /**
     * 创建腾讯云全局配置表
     * @return bool|void
     */
    public function createConfTable()
    {
        $db = $this->db;
        $serverType = $db->getServerType();
        if ($serverType != 'mysql') {
            return;
        }
        $creaTabSql = 'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__tencentcloud_conf')
            . ' (' . $db->quoteName('name') . " varchar(100) NOT NULL DEFAULT '', "
            . $db->quoteName('site_id') . " varchar(100) NOT NULL DEFAULT '', "
            . $db->quoteName('site_url') . " varchar(255) NOT NULL DEFAULT '', "
            . $db->quoteName('uin') . " varchar(100) NOT NULL DEFAULT '' "
            . ') ENGINE=InnoDB';

        if ($db->hasUTF8mb4Support()) {
            $creaTabSql = $creaTabSql
                . ' DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;';
        } else {
            $creaTabSql = $creaTabSql
                . ' DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_unicode_ci;';
        }
        $db->setQuery($creaTabSql)->execute();
        return true;
    }


    /**
     * 创建腾讯云插件配置表
     * @return bool|void
     */
    public function createPluginConfTable()
    {
        $db = $this->db;
        $serverType = $db->getServerType();
        if ($serverType != 'mysql') {
            return;
        }
        $creaTabSql = 'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__tencentcloud_plugin_conf')
            . ' (' . $db->quoteName('type') . " varchar(20) NOT NULL DEFAULT '', "
            . $db->quoteName('uin') . " varchar(20) NOT NULL DEFAULT '',"
            . $db->quoteName('use_time') . " int(11) NOT NULL DEFAULT 0"
            . ') ENGINE=InnoDB';

        if ($db->hasUTF8mb4Support()) {
            $creaTabSql = $creaTabSql
                . ' DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;';
        } else {
            $creaTabSql = $creaTabSql
                . ' DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_unicode_ci;';
        }
        $db->setQuery($creaTabSql)->execute();
        return true;
    }


    public function dropPluginConfTable()
    {
        $db = $this->db;
        $serverType = $db->getServerType();
        if ($serverType != 'mysql') {
            return;
        }
        $creaTabSql = 'DROP TABLE IF EXISTS ' . $db->quoteName('#__tencentcloud_plugin_conf');

        $db->setQuery($creaTabSql)->execute();
        return true;
    }

    /**
     * 获取腾讯云插件配置
     * @return bool|mixed|null
     */
    private function getPluginConf()
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['type', 'uin', 'use_time']))
            ->from($db->quoteName('#__tencentcloud_plugin_conf'))
            ->where($db->quoteName('type') . " = '" . $this->plugin_type . "' limit 1");
        $db->setQuery($query);

        try {
            $row = $db->loadAssoc();
        } catch (RuntimeException $e) {
            return false;
        }

        return $row;
    }


    /**
     * 发送post请求
     * @param string  地址
     * @param mixed   参数
     */
    private static function sendPostRequest($url, $data)
    {
        DebugLog::writeDebugLog('debug', JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_SEND_DATA') . json_encode($data));
        if (function_exists('curl_init')) {
            ob_start();
            $json_data = json_encode($data);
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json_data);
            curl_setopt($curl, CURLOPT_TIMEOUT, 60);   //设置一秒超时
            curl_exec($curl);
            curl_exec($curl);
            curl_close($curl);
            ob_end_clean();
        }
    }


    /**
     * 发送用户信息（非敏感）
     * @param $data
     * @return bool|void
     */
    private function sendUserExperienceInfo($data)
    {
        if (empty($data) || !is_array($data) || !isset($data['action'])) {
            return;
        }
        $url = $this->log_server_url;
        $this->sendPostRequest($url, $data);
        return true;
    }

    /**
     * @param string $action 上报方法
     */
    public function report($action)
    {
        //数据上报
        $conf = $this->getSiteInfo();
        $pluginConf = $this->getPluginConf();
        if (isset($pluginConf, $pluginConf['uin'])) {
            $uin = $pluginConf['uin'];
        }
        $data = array(
            'action' => $action,
            'plugin_type' => $this->plugin_type,
            'data' => array(
                'site_id' => $conf['site_id'],
                'site_url' => $conf['site_url'],
                'site_app' => $this->site_app,
                'uin' => isset($uin) ? $uin : '',
                'others' => json_encode(array())
            )
        );
        $this->sendUserExperienceInfo($data);
    }
}
