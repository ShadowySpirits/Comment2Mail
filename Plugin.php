<?php
/**
 * Typecho 评论 SMTP、Mailgun 邮件通知插件
 *
 * @package Comment2Mail
 * @author SSpirits
 * @version 1.1.0
 * @link https://blog.sspirits.top
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

date_default_timezone_set('Asia/Shanghai');

class Comment2Mail_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 插件激活方法
     *
     * @static
     * @access public
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        if (!function_exists('curl_init')) {
            throw new Typecho_Plugin_Exception(_t('您需要先安装 PHP CURL 拓展'));
        }
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = array(__CLASS__, 'sendMail');
        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = array(__CLASS__, 'sendMail');
        Typecho_Plugin::factory('Widget_Comments_Edit')->mark = array(__CLASS__, 'approvedMail');
        Helper::addRoute('route_SMTP', '/send-by-smtp', 'Comment2Mail_Action', 'sendSMTP');
    }

    /**
     * 插件禁用方法
     *
     * @static
     * @access public
     */
    public static function deactivate()
    {
        Helper::removeRoute("route_SMTP");
    }

    /**
     * 插件配置方法
     *
     * @static
     * @access public
     *
     * @param Typecho_Widget_Helper_Form $form
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $normal_section = new Typecho_Widget_Helper_Layout('div');
        $normal_section->html('<h2>通用设置</h2>');
        $form->addItem($normal_section);
        $public_debug = new Typecho_Widget_Helper_Form_Element_Checkbox('public_debug', array('enable' => _t('启用 Debug')), array('enable'), _t('是否启用 Debug 模式'), _t('Debug 日志记录于插件根目录下 debug.log 中，请保证对该目录有写权限'));
        $form->addInput($public_debug);
        $public_interface = new Typecho_Widget_Helper_Form_Element_Radio('public_interface', array('smtp' => _t('SMTP'), 'mailgun' => _t('Mailgun')), 'mailgun', _t('发信接口'));
        $form->addInput($public_interface->addRule('required', _t('请选择发件接口')));
        $public_name = new Typecho_Widget_Helper_Form_Element_Text('public_name', NULL, NULL, _t('发件人名称'), _t('邮件中显示的发信人名称，留空为博客名称'));
        $form->addInput($public_name);
        $public_mail = new Typecho_Widget_Helper_Form_Element_Text('public_mail', NULL, NULL, _t('发件邮箱地址'), _t('邮件中显示的发信地址'));
        $form->addInput($public_mail->addRule('required', _t('请输入发件邮箱地址'))->addRule('email', _t('请输入正确的邮箱地址')));
        $public_replyto = new Typecho_Widget_Helper_Form_Element_Text('public_replyto', NULL, NULL, _t('邮件回复地址'), _t('附带在邮件中的默认回信地址'));
        $form->addInput($public_replyto->addRule('required', _t('请输入回信邮箱地址'))->addRule('email', _t('请输入正确的邮箱地址')));

        $smtp_section = new Typecho_Widget_Helper_Layout('div');
        $smtp_section->html('<h2>SMTP 邮件发送设置</h2>');
        $form->addItem($smtp_section);
        $smtp_host = new Typecho_Widget_Helper_Form_Element_Text('smtp_host', NULL, NULL, _t('SMTP地址'), _t('SMTP 服务器地址'));
        $form->addInput($smtp_host);
        $smtp_port = new Typecho_Widget_Helper_Form_Element_Text('smtp_port', NULL, NULL, _t('SMTP端口'), _t('SMTP 服务器连接端口，一般为 25'));
        $form->addInput($smtp_port);
        $smtp_user = new Typecho_Widget_Helper_Form_Element_Text('smtp_user', NULL, NULL, _t('SMTP登录用户'), _t('SMTP 登录用户名，一般为邮箱地址'));
        $form->addInput($smtp_user);
        $smtp_pass = new Typecho_Widget_Helper_Form_Element_Text('smtp_pass', NULL, NULL, _t('SMTP登录密码'), _t('一般为邮箱密码，但某些服务商需要生成特定密码'));
        $form->addInput($smtp_pass);
        $smtp_auth = new Typecho_Widget_Helper_Form_Element_Checkbox('smtp_auth', array('enable' => _t('服务器需要验证')), array('enable'), _t('SMTP 验证模式'));
        $form->addInput($smtp_auth);
        $smtp_secure = new Typecho_Widget_Helper_Form_Element_Radio('smtp_secure', array('none' => _t('无加密'), 'ssl' => _t('SSL 加密'), 'tls' => _t('TLS 加密')), 'none', _t('SMTP 加密模式'));
        $form->addInput($smtp_secure);

        $mailgun_section = new Typecho_Widget_Helper_Layout('div');
        $mailgun_section->html('<h2>Mailgun 邮件发送设置</h2>');
        $form->addItem($mailgun_section);
        $mailgun_api_user = new Typecho_Widget_Helper_Form_Element_Text('mailgun_api_domain', NULL, NULL, _t('API DOMAIN'), _t('请填入 Mailgun 的 Domain'));
        $form->addInput($mailgun_api_user);
        $mailgun_api_key = new Typecho_Widget_Helper_Form_Element_Text('mailgun_api_key', NULL, NULL, _t('API KEY'), _t('请填入在 Mailgun 生成的 API_KEY'));
        $form->addInput($mailgun_api_key);
    }

    /**
     * 个人配置
     *
     * @static
     * @access public
     *
     * @param Typecho_Widget_Helper_Form $form
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 发送回复邮件初始方法
     *
     * @static
     * @access public
     *
     * @param mixed $comment 评论对象
     *
     * @throws Typecho_Db_Exception
     * @throws Typecho_Plugin_Exception
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public static function sendMail($comment)
    {
        if (0 < $comment->parent) {
            $parentComment = self::getWidget('Comments', 'coid', $comment->parent);
            if (isset($parentComment->coid) && $comment->authorId != $parentComment->authorId) {
                self::send($parentComment->mail, $comment, $parentComment);
            }
            return;
        }

        if ($comment->authorId != $comment->ownerId) {
            $author = self::getWidget('Users', 'uid', $comment->ownerId);
            self::send($author->mail, $comment, NULL);
        }
    }

    /**
     * 评论审核邮件通知
     *
     * @static
     * @access public
     *
     * @param mixed $comment 评论对象
     * @param mixed $edit 编辑对象
     * @param mixed $status 评论状态
     *
     * @throws Typecho_Db_Exception
     * @throws Typecho_Plugin_Exception
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public static function approvedMail($comment, $edit, $status)
    {
        if ('approved' === $status) {
            self::send($edit->mail, $edit, NULL, TRUE);
        }
    }

    /**
     * 邮件发送选择操作
     *
     * @static
     * @access private
     *
     * @param      string $mail 收件地址
     * @param      mixed $comment 评论对象
     * @param      mixed $parentComment 上级评论对象
     * @param bool $isApproved
     *
     * @return bool|mixed
     * @throws Typecho_Db_Exception
     * @throws Typecho_Plugin_Exception
     * @throws \PHPMailer\PHPMailer\Exception
     */
    private static function send($mail, $comment, $parentComment, $isApproved = FALSE)
    {
        $options = Helper::options();
        $plugin = $options->plugin('Comment2Mail');
        $data = array(
            'fromName' => (!isset($plugin->public_name) || $plugin->public_name === null || empty($plugin->public_name)) ? trim($options->title) : $plugin->public_name,
            'from' => $plugin->public_mail,
            'to' => $mail,
            'replyTo' => $plugin->public_replyto,
        );
        if ($isApproved) {
            $data['subject'] = '您在 [' . trim($options->title) . ']  发表的文章有新评论！';
            $html = file_get_contents(__DIR__ . '/theme/approved.html');
            $data['html'] = str_replace(array(
                '{blogUrl}',
                '{blogName}',
                '{author}',
                '{permalink}',
                '{title}',
                '{text}'
            ), array(
                trim($options->siteUrl),
                trim($options->title),
                trim($comment->author),
                trim($comment->permalink),
                trim($comment->title),
                str_replace(PHP_EOL, '<br>', trim($comment->text))
            ), $html);
        } elseif ($parentComment !== null) {
            $data['subject'] = '您在 [' . $options->title . '] 的评论有了新的回复！';
            $html = file_get_contents(__DIR__ . '/theme/reply.html');
            $post = self::getWidget('Contents', 'cid', $parentComment->cid);
            $data['html'] = str_replace(array(
                '{blogUrl}',
                '{blogName}',
                '{author}',
                '{permalink}',
                '{title}',
                '{text}',
                '{replyAuthor}',
                '{replyText}',
                '{commentUrl}'
            ), array(
                trim($options->siteUrl),
                trim($options->title),
                trim($parentComment->author),
                trim($post->permalink),
                trim($post->title),
                str_replace(PHP_EOL, '<br>', trim($parentComment->text)),
                trim($comment->author),
                str_replace(PHP_EOL, '<br>', trim($comment->text)),
                trim($comment->permalink)
            ), $html);
        } else {
            $data['subject'] = '您在 [' . $options->title . ']  发表的文章有新评论！';
            $html = file_get_contents(__DIR__  . '/theme/author.html');
            $data['html'] = str_replace(array(
                '{blogUrl}',
                '{blogName}',
                '{author}',
                '{permalink}',
                '{title}',
                '{text}'
            ), array(
                trim($options->siteUrl),
                trim($options->title),
                trim($comment->author),
                trim($comment->permalink),
                trim($comment->title),
                str_replace(PHP_EOL, '<br>', trim($comment->text))
            ), $html);
        }

        switch ($plugin->public_interface) {
            case 'mailgun':
                $data['from'] = ((!isset($plugin->public_name) || $plugin->public_name === null || empty($plugin->public_name)) ? trim($options->title) : $plugin->public_name) . '<' . $plugin->public_mail . '>';
                return self::mailGun($data, $plugin->mailgun_api_domain, $plugin->mailgun_api_key);
            case 'smtp':
                $data['smtp_host'] = $plugin->smtp_host;
                $data['smtp_port'] = $plugin->smtp_port;
                $data['smtp_user'] = $plugin->smtp_user;
                $data['smtp_pass'] = $plugin->smtp_pass;
                $data['smtp_auth'] = $plugin->smtp_auth;
                $data['smtp_secure'] = $plugin->smtp_secure;
                return self::smtp($data);
        }
        return '';
    }

    /**
     * 获取 Widget 对象
     *
     * @static
     * @access private
     *
     * @param string $name Widget名称
     * @param string $key 查询关键字
     * @param mixed $val 查询值
     *
     * @return mixed
     * @throws Typecho_Db_Exception
     */
    private static function getWidget($name, $key, $val)
    {
        $className = 'Widget_Abstract_' . $name;
        $widget = new $className(new Typecho_Request(), new Typecho_Response(), NULL);
        $db = Typecho_Db::get();
        $select = $widget->select()->where($key . ' = ?', $val)->limit(1);
        $db->fetchRow($select, array($widget, 'push'));
        return $widget;
    }

    /**
     * 使用 Mailgun curl 发送邮件
     *
     * @static
     * @access private
     *
     * @param array $param 请求参数
     *
     * @return mixed
     * @throws Typecho_Plugin_Exception
     */
    private static function mailGun($param, $domain, $apikey)
    {
        $stime = microtime(true);
        $ch = curl_init();
        $url = 'https://api.mailgun.net/v2/' . $domain . '/messages';
        curl_setopt($ch, CURLOPT_USERPWD, 'api:' . $apikey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $param);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $result = curl_exec($ch);
        curl_close($ch);
        $etime = microtime(true);

        $plugin = Helper::options()->plugin('Comment2Mail');
        if (in_array('enable', $plugin->public_debug)) {
            $log = '[Mailgun] ' . date('Y-m-d H:i:s') . ': ' . PHP_EOL;
            $log .= serialize($result) . PHP_EOL;
            $log .= 'execution time：' . ($etime - $stime) . PHP_EOL;
            $log .= 'to：' . $param['to'] . PHP_EOL;
            $log .= 'email content：' . $param['html'] . PHP_EOL;
            $log .= '-------------------------------------------' . PHP_EOL . PHP_EOL . PHP_EOL;
            file_put_contents(__DIR__ . '/debug.log', $log, FILE_APPEND);
        }
        return $result;
    }

    /**
     * 使用 SMTP 发送邮件
     *
     * @static
     * @access private
     *
     * @param $param
     *
     * @return bool
     * @throws Typecho_Plugin_Exception
     * @throws \PHPMailer\PHPMailer\Exception
     */
    private static function smtp($param)
    {
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL,Helper::options()->siteUrl . '/send-by-smtp');
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($param));
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_exec($ch);
        curl_close($ch);
        return true;
    }
}