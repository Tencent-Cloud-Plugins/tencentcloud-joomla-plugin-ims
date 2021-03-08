<?php
/**
 * 调试日志操作类
 * 功能列表如下：
 * 1 writeDebugLog 输出调试信息
 * @author jerry
 */

if (!class_exists('DebugLog')) {
    class DebugLog
    {
        protected $_enable = false;
        protected $_file_ext;
        protected $_log_path;
        protected $_file_permissions;
        protected $_date_fmt;
        protected $_func_overload;

        // 单例模式
        private static $instance = NULL;

        private function __construct()
        {
        }

        /**
         * 初始化调试日志操作类，没有经过初始化的后续调试代码都不会生效
         */
        public static function _init()
        {
            if (!self::$instance) {
                self::$instance = new DebugLog();

                self::$instance->_file_ext = 'log';
                self::$instance->_log_path = '/tmp/log/';
                self::$instance->_file_permissions = 0644;
                self::$instance->_date_fmt = 'Y-m-d H:i:s';
                self::$instance->_func_overload = false;
                self::$instance->_enable = false;
            }
        }


        /**
         * 将消息输出到指定的文件
         * @param $level
         * @param string $msg 消息内容
         * @param string $file 日志文件名称，默认是 qcloud_rain_php.log
         * @return bool
         * @throws Exception
         */
        public static function writeDebugLog($level, $msg)
        {
            self::_init();
            if (self::$instance->_enable === false) {
                return FALSE;
            }

            if (!is_dir(self::$instance->_log_path)) {
                @mkdir(self::$instance->_log_path, 0777);
            }
            $level = strtoupper($level);
            $filepath = self::$instance->_log_path . 'log-' . date('Y-m-d') . '.' . self::$instance->_file_ext;

            $message = "";
            if (!file_exists($filepath)) {
                $newfile = TRUE;
            }

            if (!$fp = @fopen($filepath, 'ab')) {
                return FALSE;
            }
            flock($fp, LOCK_EX);

            if (strpos(self::$instance->_date_fmt, 'u') !== FALSE) {
                $microtime_full = microtime(TRUE);
                $microtime_short = sprintf("%06d", ($microtime_full - floor($microtime_full)) * 1000000);
                $date = new DateTime(date('Y-m-d H:i:s.' . $microtime_short, $microtime_full));
                $date = $date->format(self::$instance->_date_fmt);
            } else {
                $date = date(self::$instance->_date_fmt);
            }

            $message .= self::$instance->_format_line($level, $date, $msg);

            for ($written = 0, $length = self::$instance->strlen($message); $written < $length; $written += $result) {
                if (($result = fwrite($fp, self::$instance->substr($message, $written))) === FALSE) {
                    break;
                }
            }

            flock($fp, LOCK_UN);
            fclose($fp);

            if (isset($newfile) && $newfile === TRUE) {
                chmod($filepath, self::$instance->_file_permissions);
            }

            return is_int($result);
        }

        /**
         * 格式化输出字符串
         * @param $level
         * @param $date
         * @param $message
         * @return string
         */
        private function _format_line($level, $date, $message)
        {
            return $level . ', ' . $date . ', ' . $message . "\n";
        }

        /**
         * 获取字符串长度，支持中文
         * @param $str
         * @return false|int
         */
        private static function strlen($str)
        {
            return (self::$instance->_func_overload)
                ? mb_strlen($str, '8bit')
                : strlen($str);
        }

        /**
         * 获取子字符串，支持中文
         * @param $str
         * @param $start
         * @param null $length
         * @return false|string
         */
        private static function substr($str, $start, $length = NULL)
        {
            if (self::$instance->_func_overload) {
                isset($length) or $length = ($start >= 0 ? self::strlen($str) - $start : -$start);
                return mb_substr($str, $start, $length, '8bit');
            }

            return isset($length)
                ? substr($str, $start, $length)
                : substr($str, $start);
        }
    }
}
