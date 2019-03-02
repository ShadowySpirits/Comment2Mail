<?php

class Comment2Mail_Action extends Widget_Abstract_Contents implements Widget_Interface_Do
{
    public function action() {}

    public function sendSMTP($param = array())
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Typecho_Widget_Exception(_t('请求的地址不存在'), 404);
        }
        if (empty($param)) {
            $param = $_POST;
        }
        $stime = microtime(true);
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            require __DIR__ . '/lib/PHPMailer.php';
        }

        if (!class_exists(\PHPMailer\PHPMailer\SMTP::class)) {
            require __DIR__ . '/lib/SMTP.php';
        }

        if (!class_exists('PHPMaile\PHPMailer\Exception')) {
            require __DIR__ . '/lib/Exception.php';
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(FALSE);
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host = $param['smtp_host'];
        $mail->Port = $param['smtp_port'] ?: 25;
        $mail->Username = $param['smtp_user'];
        $mail->Password = $param['smtp_pass'];

        if (in_array('enable', $param['smtp_auth'])) {
            $mail->SMTPAuth = TRUE;
        }

        if ('none' !== $param['smtp_secure']) {
            $mail->SMTPSecure = $param['smtp_secure'];
        }

        $mail->setFrom($param['from'], $param['fromName']);
        $mail->addReplyTo($param['replyTo'], $param['fromName']);
        $mail->addAddress($param['to']);
        $mail->isHTML(TRUE);
        $mail->SMTPDebug = 4;
        $mail->Subject = $param['subject'];
        $mail->msgHTML($param['html']);
        $result = $mail->send();
        $etime = microtime(true);

        $plugin = Helper::options()->plugin('Comment2Mail');
        if (in_array('enable', $plugin->public_debug)) {
            $log = '[SMTP] ' . date('Y-m-d H:i:s') . ': ' . PHP_EOL;
            if (!empty($mail->ErrorInfo)) $log .= serialize($result) . '; PHPMailer error: ' . $mail->ErrorInfo . PHP_EOL;
            $log .= 'execution time：' . ($etime - $stime) . PHP_EOL;
            $log .= 'to：' . $param['to'] . PHP_EOL;
            $log .= 'email content：' . $param['html'] . PHP_EOL;
            $log .= '-------------------------------------------' . PHP_EOL . PHP_EOL . PHP_EOL;
            file_put_contents(__DIR__ . '/debug.log', $log, FILE_APPEND);
        }
        return $result;
    }
}