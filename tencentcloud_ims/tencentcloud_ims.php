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

require_once 'TencentcloudImsAction.php';
class PlgContentTencentcloud_ims extends JPlugin{

    /**
     * Load the language file on instantiation.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;

    /**
     * Smart Search after save content method.
     * Content is passed by reference, but after the save, so no changes will be saved.
     * Method is called right after the content is saved.
     *
     * @param   string  $context  The context of the content passed to the plugin (added in 1.6)
     * @param   object  $article  A JTableContent object
     * @param   bool    $isNew    If the content has just been created
     *
     * @return  void
     *
     * @since   2.5
     */
    public function onContentBeforeSave($context, $article, $isNew)
    {
        // 多媒体文件管理上传附件
        if ($context === 'com_media.file')
        {
            if (!file_exists($article->tmp_name)) {
                DebugLog::writeDebugLog('debug', JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_FILE_UNEXIST'));
                $article->setError(JText::_('PLG_CONTENT_TENCENTCLOUD_IMS_FILE_UNEXIST'));
                return false;
            }
            return (new TencentcloudImsAction())->AuditImageInMedia($article);
        }
    }
}
