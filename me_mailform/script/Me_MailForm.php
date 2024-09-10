<?php
/**
 * MicroEngine MailForm
 * https://microengine.jp/mailform/
 *
 * @copyright Copyright (C) 2014-2023 MicroEngine Inc.
 * @version 1.1.11
 */
require_once('simple_html_dom.php');
/**
 * メールフォーム
 */
class Me_MailForm
{
    /** システム文字コード */
    const SYSTEM_CHAR_CODE = 'UTF-8';
    /** CSV文字コード */
    const CSV_CHAR_CODE = 'SJIS-win';
    /** 入力ステップ名 */
    const ENTRY = 'entry';
    /** 確認ステップ名 */
    const CONFIRM = 'confirm';
    /** 送信ステップ名 */
    const SEND = 'send';
    /** 郵便番号検索 */
    const ZIPCODE = 'zipcode';
    /** CAPTCHA */
    const CAPTCHA = 'captcha';
    /** エラーメッセージ用要素のIDにつける接尾辞 */
    const ERROR_ID_SUFFIX = '_error';
    /** ステップパラメータ用 name属性の値 */
    const STEP_PARAMETER = '_step';
    /** 戻るボタン用 name属性の値 */
    const BACK_PARAMETER = '_back';
    /** 基本設定ファイルパス */
    const CONFIG_FILE = '/config/config.ini';
    /** Mail 設定ファイルパス */
    const MAIL_CONFIG_FILE = '/config/mail.ini';
    /** Message 設定ファイルパス */
    const MESSAGE_CONFIG_FILE = '/config/message.ini';
    /** アイテム（フォーム項目）設定ファイルパス */
    const ITEM_FILE = '/config/item.ini';
    /** 本文ファイルパス（拡張子省略） */
    const BODY_FILE = '/config/body';
    /** 自動返信本文ファイルパス（拡張子省略） */
    const REPLY_BODY_FILE = '/config/reply_body';
    /** メールログディレクトリ */
    const MAIL_LOG_DIR = '/log/';
    /** メールログファイル名 */
    const MAIL_LOG_FILENAME = 'qdmail.log';
    /** メールエラーログファイル名 */
    const MAIL_ERROR_LOG_FILENAME = 'qdmail_error.log';
    /** CSV保存ディレクトリ */
    const CSV_SAVE_DIR = '/csv/';
    /** メール文字セット */
    const MAIL_CHARSET = 'UTF-8';
    /** メールエンコード */
    const MAIL_ENCODING = 'base64';

    /**
     * 設定配列
     * @var array
     */
    private $config;
    /**
     * Mail 設定配列
     * @var array
     */
    private $mail_config;
    /**
     * Message 設定配列
     * @var array
     */
    private $message_config;
    /**
     * アイテム（フォーム項目）配列
     * @var array
     */
    private $form_item;
    /**
     * ステップ名
     * @var string
     */
    private $step;
    /**
     * エラー発生フラグ
     * @var boolean
     */
    private $is_error = false;
    /**
     * 文字コード変換フラグ
     * @var boolean
     */
    private $convert_char_code = false;
    /**
     * 処理時のタイムスタンプ
     * @var int
     */
    private $now;

    /**
     * コンストラクタ
     */
    public function __construct()
    {
        // 内部文字コード指定
        ini_set('default_charset', self::SYSTEM_CHAR_CODE);
        mb_internal_encoding(self::SYSTEM_CHAR_CODE);

        // 設定ファイル読み込み
        $this->load_config();

        // エラーレベル設定
        $this->set_error_level();

        // Cookie設定
        ini_set('session.cookie_httponly', 1);
        if (isset($this->config['security']['cookie_secure'])) {
            ini_set('session.cookie_secure', $this->config['security']['cookie_secure']);
        }

        // キャッシュ設定
        $this->set_cache_limiter();

        // セッション開始
        session_name('Me_MailForm');
        session_start();

        // magic_quotes_gpc対策
        $this->against_magic_quotes();

        // テンプレートファイルの文字コード設定
        $this->set_template_char_code();

        // タイムゾーン設定
        $this->set_timezone();
    }

    /**
     * アクション実行
     */
    public function run()
    {
        // ステップ判定
        $this->set_step();

        // tokenチェック
        $this->check_token();

        // POST値をアイテムにセットする
        if ($this->step === self::ENTRY ||$this->step === self::CONFIRM || $this->step === self::SEND) {
            $this->set_item_value();
        }

        // POST値の変換・バリデーション
        if ($this->step === self::CONFIRM || $this->step === self::SEND) {
            // convert
            $this->convert();
            // validation
            $this->validate(false);
            // 計算する
            $this->calculate();
            // 加工する
            $this->process();
            // 計算後にpriceタイプをバリデーションする
            $this->validate(true);
        }

        // 選択されたステップの処理を実行
        $action = $this->step;
        $this->$action();
    }

    /**
     * 入力ステップ処理
     */
    private function entry()
    {
        // テンプレートを取得
        $html = $this->get_template($this->config['step'][$this->step]);

        // フォーム要素を取得
        $form = $this->get_form($html);

        // フォームに値をセットする
        $this->set_form($form);

        // ID指定された要素に値をセットする
        $this->output_item_value($form);

        // エラーメッセージ見出し
        // 入力画面のテンプレートには、エラー発生を知らせるための表示をしておく。
        // エラーが発生しなかったら、その要素を削除する。
        if (!$this->is_error) {
            $html->find('#' . $this->message_config['message']['error_message_id'], 0)->outertext = '';
        }

        // ステップパラメータと token をフォームに追加
        $form->find('text', 0)->outertext .= $this->get_hidden_tag(self::STEP_PARAMETER, $this->get_next_step()) . $this->get_token();

        // HTML書き出し
        $this->render($html);
    }

    /**
     * 確認ステップ処理
     */
    private function confirm()
    {
        // セッションIDがあれば再生成する
        if (session_id()) {
            session_regenerate_id(true);
        }

        // テンプレートを取得
        $html = $this->get_template($this->config['step'][$this->step]);

        // アイテムの値をテンプレートに埋め込む
        $this->output_item_value($html);

        // フォーム要素を取得
        $form = $this->get_form($html);

        // hidden要素と token をフォームに追加
        $form->innertext .= $this->get_hidden_tags() . $this->get_token();

        // HTML書き出し
        $this->render($html);
    }

    /**
     * 送信ステップ
     */
    private function send()
    {
        // to 設定確認
        if (strlen($this->mail_config['mail']['to']) < 1) {
            $this->error_screen($this->message_config['message']['msg_unset_to']);
        }

        // 通し番号処理
        $this->issue_serial();

        // 添付ファイルをアップロードディレクトリに保存する
        $this->save_file();

        // 送信者情報
        $this->set_sender_info();

        // 連続送信確認
        $this->check_limitation();

        // メール送信処理
        $this->send_mail();

        // CSV保存
        $this->save_csv();

        // CAPTCHAのキーを削除
        unset($_SESSION['captcha_keystring']);

        // 添付ファイルを削除
        unset($_SESSION['attach_files']);

        // トークンを削除
        unset($_SESSION['_token']);

        if (isset($this->config['flow']['redirect'])) {
            // リダイレクト処理
            header('Location: ' . $this->config['flow']['redirect']);
            exit;
        } else {
            // テンプレートを取得
            $html = $this->get_template($this->config['step'][$this->step]);

            // アイテムの値をテンプレートに埋め込む
            $this->output_item_value($html);

            // HTML書き出し
            $this->render($html);
        }
    }

    /**
     * 郵便番号検索
     */
    private function zipcode()
    {
        $code = mb_convert_kana($_GET['_zipcode'], 'n');
        if (!is_numeric($code)) {
            echo json_encode(false);
            return;
        }

        try {
            $con = $this->get_pdo_instance();
            $sql = 'select * from ZIPCODE where code = :code';
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':code', $code);
            $stmt->execute();
        } catch (PDOException $e) {
            $this->error_screen('Exception: ' . $e->getMessage());
        } catch (Exception $e) {
            $this->error_screen('Exception: ' . $e->getMessage());
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($result);
        return;
    }

    /**
     * CAPTCHA
     */
    private function captcha()
    {
        include('.' . ME_MAILFORM_DIR . 'script/captcha/autoload.php');

        $phraseBuilder = new \Gregwar\Captcha\PhraseBuilder(
            $this->config['captcha']['length'],
            $this->config['captcha']['allowed_symbols']
        );
        $builder = new \Gregwar\Captcha\CaptchaBuilder(null, $phraseBuilder);
        $builder->build($this->config['captcha']['width'], $this->config['captcha']['height']);

        if($_COOKIE[session_name()] || $_GET[session_name()]){
            $_SESSION['captcha_keystring'] = $builder->getPhrase();
        }

        // 画像はキャッシュさせない
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');

        header('Content-type: image/jpeg');
        $builder->output();
    }

    /**
     * 設定ファイル読み込み
     */
    private function load_config()
    {
        // v1.0.4 以降は設定ファイルを書き換えずにアップグレードできるように初期設定値を持たせる。

        // config.ini
        $config_master = array(
            'security' => array(
                'max_sends' => 0,
                'send_period' => 600,
            ),
            'captcha' => array(
                'length' => 5,
                'allowed_symbols' => '0123456789',
                'width' => 150,
                'height' => 40,
            ),
            'upload_file' => array(
                'attach_mail' => 1,
                'save_file' => 0,
                'extensions' => 'jpg, gif, png, zip',
                'max_filesize' => 10,
                'upload_file_path' => '{random}/{filename}',
            ),
            'validation' => array(
                'error_parents' => 0,
                'error_class' => 'alert alert-warning',
                'not_has_line_break' => 1,
            ),
        );
        $this->config = array_replace_recursive(
            $config_master,
            parse_ini_file(DATA_ROOT . self::CONFIG_FILE, true)
        );

        // mail.ini
        $this->mail_config = parse_ini_file(DATA_ROOT . self::MAIL_CONFIG_FILE, true);

        // message.ini
        $message_master = array(
            'message' => array(
                'msg_max_sends' => 'メールの送信可能な上限数に達しています。申し訳ありませんが、時間をおいてから再度お試しください。',
                'msg_minlength' => '{label}は {minlength} 文字以上を入力してください。',
                'msg_required_if' => '選択された{other}の場合は、{label}を入力してください。',
                'msg_required_unless' => '選択された{other}の場合は、{label}を入力してください。',
                'msg_regex' => '{label}は入力可能な書式と一致しません。入力内容を見直してください。',
                'msg_regex_not' => '{label}は入力可能な書式と一致しません。入力内容を見直してください。',
                'msg_extensions' => 'アップロードできるファイルの拡張子は「{extensions}」です。',
                'msg_max_filesize' => 'アップロードできるファイルサイズの最大値は {max_filesize}MBです。',
                'msg_calculate' => '計算に失敗しました。計算式：{expression}',
                'msg_max_number' => '{label}は {number} 以下を入力してください。',
                'msg_min_number' => '{label}は {number} 以上を入力してください。',
                'msg_form_not_found' => 'Form要素が見つかりません。',
            ),
        );
        $this->message_config = array_replace_recursive(
            $message_master,
            parse_ini_file(DATA_ROOT . self::MESSAGE_CONFIG_FILE, true)
        );

        // item.ini
        $this->form_item = parse_ini_file(DATA_ROOT . self::ITEM_FILE, true);
    }

    /**
     * エラーレベル設定
     */
    private function set_error_level()
    {
        // エラーレベル設定
        if (isset($this->config['global']['debug']) && $this->config['global']['debug']) {
            if (PHP_VERSION_ID >= 50400) {
                error_reporting(E_ALL ^ E_NOTICE ^ E_STRICT);
            } else {
                error_reporting(E_ALL ^ E_NOTICE);
            }
            ini_set('display_errors', 1);
        } else {
            error_reporting(0);
        }
    }

    /**
     * キャッシュ設定
     */
    private function set_cache_limiter()
    {
        // 確認画面を使わない場合は、キャッシュ設定は行わない。
        if (!$this->config['flow']['use_confirm']) {
            return;
        }
        $cache_limiter = 'private_no_expire';
        if (isset($this->config['global']['session.cache_limiter'])) {
            $cache_limiter = $this->config['global']['session.cache_limiter'];
        }
        session_cache_limiter($cache_limiter);
    }

    /**
     * magic_quotes_gpc対策
     */
    private function against_magic_quotes()
    {
        // PHP 5.4.0 から magic_quotes_gpc 設定が削除された。それより古い場合だけ処理する
        if (PHP_VERSION_ID < 50400 && get_magic_quotes_gpc()) {
            $_POST = $this->stripslashes_deep($_POST);
        }
    }

    /**
     * クォートを再帰的に取り除く
     * @param mixed $arr
     */
    private function stripslashes_deep($arr)
    {
        return is_array($arr) ?
            array_map(array('Me_MailForm', 'stripslashes_deep'), $arr) :
            stripslashes($arr);
    }

    /**
     * テンプレートファイルの文字コード設定
     */
    private function set_template_char_code()
    {
        if (is_null($this->config['global']['char_code'])) {
            $this->config['global']['char_code'] = self::SYSTEM_CHAR_CODE;
        }
        $this->convert_char_code = ($this->config['global']['char_code'] !== self::SYSTEM_CHAR_CODE);
    }

    /**
     * タイムゾーン設定
     */
    private function set_timezone()
    {
        if (isset($this->config['Date']['date.timezone'])) {
            date_default_timezone_set($this->config['Date']['date.timezone']);
        }
        // 処理時用のタイムスタンプをセット
        $this->now = time();
    }

    /**
     * ステップ判定
     */
    private function set_step()
    {
        if (isset($_GET['_zipcode'])) {
            $this->step = self::ZIPCODE;
            return;
        }
        if (isset($_GET['captcha'])) {
            $this->step = self::CAPTCHA;
            return;
        }

        $step = isset($_POST[self::STEP_PARAMETER]) ? $_POST[self::STEP_PARAMETER] : '';
        switch ($step) {
            case self::SEND:
                $this->step = self::SEND;
                break;
            case self::CONFIRM:
                $this->step = self::CONFIRM;
                break;
            case self::ENTRY:
            default:
                $this->step = self::ENTRY;
                break;
        }
        // 戻るボタンが押された場合
        if (isset($_POST[self::BACK_PARAMETER]) ||
            (isset($_POST[self::BACK_PARAMETER . '_x']) && isset($_POST[self::BACK_PARAMETER . '_y']))
        ) {
            $this->step = self::ENTRY;
        }
        return;
    }

    /**
     * tokenチェック
     */
    private function check_token()
    {
        if ($this->step === self::SEND && $this->config['security']['token']
            && (!isset($_SESSION['_token']) || $_SESSION['_token'] !== $_POST['_token'])) {
            $this->error_screen($this->message_config['message']['msg_token']);
        }
    }

    /**
     * アイテムに値をセットする
     */
    private function set_item_value()
    {
        // getされた値をアイテムの値にセットする
        foreach ($this->form_item as &$item) {
            if (isset($item['get_param']) && strlen($item['get_param']) > 0 && isset($_GET[$item['get_param']])) {
                $item['value'] = $_GET[$item['get_param']];
            }
        }

        // postされた値をアイテムの値にセットする
        foreach (array_keys($this->form_item) as $name) {
            if (isset($_POST[$name])) {
                $this->form_item[$name]['value'] = $this->convert_encoding($_POST[$name]);
            }
        }
    }

    /**
     * 文字列変換
     */
    private function convert()
    {
        foreach ($this->form_item as &$item) {
            // 全角・半角変換
            if (isset($item['convert_kana']) && $item['convert_kana'] && $this->item_has_single_value($item)) {
                $item['value'] = mb_convert_kana($item['value'], $item['convert_kana']);
            }
            // 大文字変換
            if (isset($item['convert_upper']) && $item['convert_upper'] && $this->item_has_single_value($item)) {
                $item['value'] = mb_strtoupper($item['value']);
            }
            // 大文字変換
            if (isset($item['convert_lower']) && $item['convert_lower'] && $this->item_has_single_value($item)) {
                $item['value'] = mb_strtolower($item['value']);
            }
        }
    }

    /**
     * 入力値チェック
     */
    private function validate($calculated = false)
    {
        // validation
        foreach ($this->form_item as $name => &$item) {
            // 改行文字確認
            if (!$this->not_has_line_break($item)) {
                $this->set_error_message($item, 'msg_not_has_line_break', array('{label}'=>$item['label']));
                $this->is_error = true;
                continue;
            }
            // price
            if ($calculated && $item['type'] !== 'price') {
                // 計算後は priceタイプのみを処理する。
                continue;
            } else if (!$calculated && $item['type'] === 'price') {
                // 計算前は priceタイプは処理しない。
                continue;
            }
            // 添付ファイル
            if ($item['type'] === 'file') {
                if (isset($_FILES[$name]['error']) && $_FILES[$name]['error'] !== UPLOAD_ERR_OK) {
                    // ファイルアップロード時にエラーが発生
                    $error = isset($_FILES[$name]['error']) ? $_FILES[$name]['error'] : null;
                    switch ($error) {
                        case UPLOAD_ERR_INI_SIZE:
                            $this->set_error_message($item, 'msg_max_filesize', array('{max_filesize}' => $this->config['upload_file']['max_filesize']));
                            $this->is_error = true;
                            break;
                        case UPLOAD_ERR_NO_FILE:
                            // ファイルがない場合もエラーにはしない。
                            break;
                        default:
                            break;
                    }
                } else if (!empty($_FILES[$name]['name'])) {
                    // 正常にファイルがアップロードされた

                    // ファイル名・拡張子取得
                    $save_file_name = basename($_FILES[$name]['name']);
                    $extension = pathinfo($save_file_name, PATHINFO_EXTENSION);

                    // 拡張子確認
                    if (!empty($this->config['upload_file']['extensions'])) {
                        $extensions = $this->str_to_array($this->config['upload_file']['extensions']);
                        if (array_search(strtolower($extension), $extensions) === false) {
                            $this->set_error_message($item, 'msg_extensions', array('{extensions}' => $this->config['upload_file']['extensions']));
                            $this->is_error = true;
                            continue;
                        }
                    }

                    // ファイルサイズ確認
                    if (!empty($this->config['upload_file']['max_filesize'])) {
                        $max_filesize = (int) ($this->config['upload_file']['max_filesize'] * 1024 * 1024);
                        if ($_FILES[$name]['size'] > $max_filesize) {
                            $this->set_error_message($item, 'msg_max_filesize', array('{max_filesize}' => $this->config['upload_file']['max_filesize']));
                            $this->is_error = true;
                            continue;
                        }
                    }

                    // 要素に値をセット
                    $item['value'] = $save_file_name;

                    // ファイルをセッションに保存する
                    if (!isset($_SESSION['attach_files']) || !is_array($_SESSION['attach_files'])) {
                        $_SESSION['attach_files'] = array();
                    }
                    $_SESSION['attach_files'][$name] = file_get_contents($_FILES[$name]['tmp_name']);
                    // ファイル名をセッションに保存する
                    $_SESSION['attach_file_names'][$name] = $save_file_name;
                }
            }
            // メールアドレス書式簡易チェック
            if ($item['type'] === 'email' && $this->item_has_single_value($item)
                && !preg_match('/\A[a-zA-Z0-9.+_-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\z/u', $item['value'])) {
                $this->set_error_message($item, 'msg_email', array('{label}'=>$item['label']));
                $this->is_error = true;
                continue;
            }
            // 最大文字数チェック
            if (isset($item['maxlength']) && $item['maxlength'] && !is_array($item['value']) && mb_strlen($item['value']) > $item['maxlength']) {
                $this->set_error_message($item, 'msg_maxlength',
                    array('{maxlength}'=>$item['maxlength'], '{label}'=>$item['label']));
                $this->is_error = true;
                continue;
            }
            // 最小文字数チェック
            // １文字以上入力された場合のみチェックする。必須にしたい場合は別途 required を指定する
            if (isset($item['minlength']) && $item['minlength'] && $this->item_has_single_value($item) && mb_strlen($item['value']) < $item['minlength']) {
                $this->set_error_message($item, 'msg_minlength',
                    array('{minlength}'=>$item['minlength'], '{label}'=>$item['label']));
                $this->is_error = true;
                continue;
            }
            // 最大数値チェック
            if (isset($item['max_number']) && $this->item_has_single_value($item)) {
                if (!is_numeric($item['value']) || $item['value'] > $item['max_number']) {
                    $this->set_error_message($item, 'msg_max_number',
                        array('{number}'=>$item['max_number'], '{label}'=>$item['label']));
                    $this->is_error = true;
                    continue;
                }
            }
            // 最小数値チェック
            if (isset($item['min_number']) && $this->item_has_single_value($item)) {
                if (!is_numeric($item['value']) || $item['value'] < $item['min_number']) {
                    $this->set_error_message($item, 'msg_min_number',
                        array('{number}'=>$item['min_number'], '{label}'=>$item['label']));
                    $this->is_error = true;
                    continue;
                }
            }
            // 必須項目チェック
            if (isset($item['required']) && $item['required'] && (!array_key_exists('value', $item) || !$this->validate_has_value($item['value']))) {
                if ($item['type'] === 'select' || $item['type'] === 'radio' || $item['type'] === 'file') {
                    $this->set_error_message($item, 'msg_required_option', array('{label}'=>$item['label']));
                } else if ($item['type'] === 'checkbox') {
                    $this->set_error_message($item, 'msg_required_check', array('{label}'=>$item['label']));
                } else {
                    $this->set_error_message($item, 'msg_required', array('{label}'=>$item['label']));
                }
                $this->is_error = true;
                continue;
            }
            // 他のフィールドのいずれかが存在している場合は必須
            if (isset($item['required_with']) && $item['required_with']) {
                $exists = false;
                $others = $this->str_to_array($item['required_with']);
                foreach ($others as $other) {
                    if ($this->validate_has_value($this->form_item[$other]['value'])) {
                        $exists = true;
                        break;
                    }
                }
                if ($exists && !$this->item_has_value($item)) {
                    $others_label = $this->get_items_label($others);
                    $this->set_error_message($item, 'msg_required_with', array('{label}'=>$item['label'], '{others}'=>$others_label));
                    $this->is_error = true;
                    continue;
                }
            }
            // 他のフィールドの全てが存在している場合は必須
            if (isset($item['required_with_all']) && $item['required_with_all']) {
                $exists = true;
                $others = $this->str_to_array($item['required_with_all']);
                foreach ($others as $other) {
                    if (!$this->validate_has_value($this->form_item[$other]['value'])) {
                        $exists = false;
                        break;
                    }
                }
                if ($exists && !$this->item_has_value($item)) {
                    $others_label = $this->get_items_label($others);
                    $this->set_error_message($item, 'msg_required_with_all', array('{label}'=>$item['label'], '{others}'=>$others_label));
                    $this->is_error = true;
                    continue;
                }
            }
            // 他のフィールドのいずれかが存在しない場合は必須
            if (isset($item['required_without']) && $item['required_without']) {
                $exists = true;
                $others = $this->str_to_array($item['required_without']);
                foreach ($others as $other) {
                    if (!$this->validate_has_value($this->form_item[$other]['value'])) {
                        $exists = false;
                        break;
                    }
                }
                if (!$exists && !$this->item_has_value($item)) {
                    $others_label = $this->get_items_label($others);
                    $this->set_error_message($item, 'msg_required_without', array('{label}'=>$item['label'], '{others}'=>$others_label));
                    $this->is_error = true;
                    continue;
                }
            }
            // 他のフィールドの全てが存在しない場合は必須
            if (isset($item['required_without_all']) && $item['required_without_all']) {
                $exists = false;
                $others = $this->str_to_array($item['required_without_all']);
                foreach ($others as $other) {
                    if ($this->validate_has_value($this->form_item[$other]['value'])) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists && !$this->item_has_value($item)) {
                    $others_label = $this->get_items_label($others);
                    $this->set_error_message($item, 'msg_required_without_all', array('{label}'=>$item['label'], '{others}'=>$others_label));
                    $this->is_error = true;
                    continue;
                }
            }
            // 他のフィールド値が指定値のどれかに一致する場合は必須
            if (isset($item['required_if']) && $item['required_if']) {
                $matched = false;
                $conditions = $this->str_to_array($item['required_if']);
                // 設定値がカンマ区切りで2個以上ある場合はチェックする
                if (isset($conditions[1]) ) {
                    $other = array_shift($conditions);
                    foreach ($conditions as $condition) {
                        // 指定された他のアイテムと、指定された値が一致するか確認する
                        if (is_array($this->form_item[$other]['value'])) {
                            foreach ($this->form_item[$other]['value'] as $multiple_item_value) {
                                if ($multiple_item_value === $condition) {
                                    $matched = true;
                                    // foreach ($conditions ... のループを抜ける。
                                    break 2;
                                }
                            }
                        } else {
                            if ($this->form_item[$other]['value'] === $condition) {
                                $matched = true;
                                break;
                            }
                        }
                    }
                    if ($matched && !$this->item_has_value($item)) {
                        $other_label = $this->form_item[$other]['label'];
                        $this->set_error_message($item, 'msg_required_if', array('{label}'=>$item['label'], '{other}'=>$other_label));
                        $this->is_error = true;
                        continue;
                    }
                }
            }
            // 他のアイテムが指定した値と一致しない場合は必須
            if (isset($item['required_unless']) && $item['required_unless']) {
                $matched = false;
                $conditions = $this->str_to_array($item['required_unless']);
                // 設定値がカンマ区切りで2個以上ある場合はチェックする
                if (isset($conditions[1]) ) {
                    $other = array_shift($conditions);
                    foreach ($conditions as $condition) {
                        // 指定された他のアイテムと、指定された値が一致するか確認する
                        if (is_array($this->form_item[$other]['value'])) {
                            foreach ($this->form_item[$other]['value'] as $multiple_item_value) {
                                if ($multiple_item_value === $condition) {
                                    $matched = true;
                                    // foreach ($conditions ... のループを抜ける。
                                    break 2;
                                }
                            }
                        } else {
                            if ($this->form_item[$other]['value'] === $condition) {
                                $matched = true;
                                break;
                            }
                        }
                    }
                    if (!$matched && !$this->item_has_value($item)) {
                        $other_label = $this->form_item[$other]['label'];
                        $this->set_error_message($item, 'msg_required_unless', array('{label}'=>$item['label'], '{other}'=>$other_label));
                        $this->is_error = true;
                        continue;
                    }
                }
            }
            // 半角数字のみかどうかチェックする
            if (isset($item['numeric']) && $item['numeric'] && $this->item_has_single_value($item) && !preg_match('/\A[0-9]*\z/u', $item['value'])) {
                $this->set_error_message($item, 'msg_numeric', array('{label}'=>$item['label']));
                $this->is_error = true;
                continue;
            }
            // 電話番号かどうかチェックする
            if (isset($item['phone']) && $item['phone'] && $this->item_has_single_value($item) && !preg_match('/\A\d{2,5}-\d{1,4}-\d{4}\z/u', $item['value'])) {
                $this->set_error_message($item, 'msg_phone', array('{label}'=>$item['label']));
                $this->is_error = true;
                continue;
            }
            // 郵便番号かどうかチェックする
            if (isset($item['postal']) && $item['postal'] && $this->item_has_single_value($item) && !preg_match('/\A\d{3}-\d{4}\z/u', $item['value'])) {
                $this->set_error_message($item, 'msg_postal', array('{label}'=>$item['label']));
                $this->is_error = true;
                continue;
            }
            // 入力値が同じかどうかチェックする
            if (isset($item['equal_to']) && strlen($item['equal_to']) > 0) {
                $equal_to_item = $this->form_item[$item['equal_to']];
                if ($item['value'] !== $equal_to_item['value']) {
                    $this->set_error_message($item, 'msg_equal_to',
                        array('{label}'=>$item['label'], '{equal_to_label}'=>$equal_to_item['label']));
                    $this->is_error = true;
                    continue;
                }
            }
            // 正規表現に一致することを確認する。（一致しない場合はエラー）
            if (isset($item['regex']) && strlen($item['regex']) > 0 && $this->item_has_single_value($item)) {
                if (!preg_match($item['regex'], $item['value'])) {
                    $this->set_error_message($item, 'msg_regex', array('{label}'=>$item['label']));
                    $this->is_error = true;
                    continue;
                }
            }
            // 正規表現に一致しないことを確認する。（一致する場合はエラー）
            if (isset($item['regex_not']) && strlen($item['regex_not']) > 0 && $this->item_has_single_value($item)) {
                if (preg_match($item['regex_not'], $item['value'])) {
                    $this->set_error_message($item, 'msg_regex_not', array('{label}'=>$item['label']));
                    $this->is_error = true;
                    continue;
                }
            }
            // CAPTCHA
            if ($item['type'] === 'captcha') {
                if (!isset($_SESSION['captcha_keystring']) || $_SESSION['captcha_keystring'] !== $item['value']) {
                    $this->set_error_message($item, 'msg_captcha', array('{label}'=>$item['label']));
                    $this->is_error = true;
                    continue;
                }
            }
        }

        // エラーメッセージ
        if ($this->is_error) {
            $this->step = self::ENTRY;
        }
    }

    /**
     * textarea以外で、改行文字が含まれていないことを確認する
     * @param array $item
     * @return bool
     */
    private function not_has_line_break($item)
    {
        // 設定が無効であればチェックしない
        if (!$this->config['validation']['not_has_line_break']) {
            return true;
        }
        // textarea タイプは対象外
        if ($item['type'] === 'textarea') {
            return true;
        }
        // ファイルなど value がない場合は対象外
        if (!isset($item['value'])) {
            return true;
        }
        // 値が配列の場合
        if (is_array($item['value'])) {
            foreach ($item['value'] as $key => $val) {
                if (preg_match('/\r|\n/u', $val) === 1) {
                    return false;
                }
            }
            return true;
        }
        // 値が配列以外の場合
        return preg_match('/\r|\n/u', $item['value']) === 0;
    }

    /**
     * $item['value']が定義されていて、値（1文字以上の文字列）を持っているかどうかを確認する
     * @param array $item
     * @return bool
     */
    private function item_has_value($item)
    {
        if (!array_key_exists('value', $item)) {
            return false;
        }
        return $this->validate_has_value($item['value']);
    }

    /**
     * $item['value']が定義されていて、value値が配列ではなく、値（1文字以上の文字列）を持っているかどうかを確認する
     * @param array $item
     * @return bool
     */
    private function item_has_single_value($item)
    {
        if (!array_key_exists('value', $item) || is_array($item['value'])) {
            return false;
        }
        return $this->validate_has_value($item['value']);
    }

    /**
     * $valueが値（1文字以上の文字列）を持っているかどうかを確認する
     * @param mixed(string|array) $value
     * @return bool $exists
     */
    private function validate_has_value($value)
    {
        $exists = false;

        if (is_array($value)) {
            // 配列の場合は、各要素毎に確認する。
            // 要素があっても空文字の場合もあるので文字数を確認する。
            foreach ($value as $multiple_item_value) {
                if (strlen($multiple_item_value) !== 0) {
                    $exists = true;
                    break;
                }
            }
        } else {
            if (isset($value) && strlen($value) !== 0) {
                $exists = true;
            }
        }
        return $exists;
    }

    /**
     * 計算処理
     * priceタイプの sum, product プロパティを処理する。
     */
    private function calculate()
    {
        foreach ($this->form_item as $name => &$item) {
            // price タイプ以外は処理しない
            if ($item['type'] !== 'price') {
                continue;
            }
            // 加算
            if (isset($item['sum']) && strlen($item['sum']) > 0) {
                // + 区切りで分割する
                $items = $this->str_to_array($item['sum'], '+');
                // アイテムの値を取り出す
                foreach ($items as $key => $value) {
                    if (isset($this->form_item[$value])) {
                        $items[$key] = $this->form_item[$value]['value'];
                    }
                    // 数値で無い場合は計算エラーとする。
                    if (!is_numeric($items[$key])) {
                        $this->error_screen(str_replace('{expression}', $item['sum'], $this->message_config['message']['msg_calculate']));
                    }
                }
                $item['value'] = array_sum($items);
            }
            // 乗算
            if (isset($item['product']) && strlen($item['product']) > 0) {
                // * 区切りで分割する
                $items = $this->str_to_array($item['product'], '*');
                // アイテムの値を取り出す
                foreach ($items as $key => $value) {
                    if (isset($this->form_item[$value])) {
                        $items[$key] = $this->form_item[$value]['value'];
                    }
                    // 数値で無い場合は計算エラーとする。
                    if (!is_numeric($items[$key])) {
                        $this->error_screen(str_replace('{expression}', $item['product'], $this->message_config['message']['msg_calculate']));
                    }
                }
                // 乗算結果は小数点以下を切り捨てる
                $item['value'] = floor(array_product($items));
            }
        }
    }

    /**
     * アイテム値の加工処理
     */
    private function process()
    {
        foreach ($this->form_item as &$item) {
            // 文字列連結
            if (isset($item['concat']) && strlen($item['concat'])) {
                $item['value'] = $this->replace_text($item['concat']);
            }
        }
    }

    /**
     * 指定した複数のアイテムのラベルを連結して返す
     * @param array $items
     * @return string
     */
    private function get_items_label($items)
    {
        $item_labels = array();
        foreach ($items as $item) {
            $item_labels[] = $this->form_item[$item]['label'];
        }
        return implode(', ', $item_labels);
    }

    /**
     * テンプレート取得
     * @param string $template_name
     * @return simple_html_dom
     */
    private function get_template($template_name)
    {
        $template_path = '.' . ME_MAILFORM_DIR . 'template/' . $template_name;
        // テンプレート内容
        $template = '';

        if (pathinfo($template_path, PATHINFO_EXTENSION) === 'php') {
            // 拡張子がphpであれば、includeしてPHPとして評価する。

            // バッファリング制御
            ob_start();
            include($template_path);
            $template = ob_get_contents();
            //バッファを削除
            ob_end_clean();
        } else {
            // php でなければ、そのまま読み込む
            $template = file_get_contents($template_path);
        }
        // 文字コード変換
        $template = $this->convert_encoding($template);

        return new simple_html_dom($template, true, true, self::SYSTEM_CHAR_CODE, false);
    }

    /**
     * フォーム要素を取得
     * @param simple_html_dom $html
     * @return simple_html_dom $form
     */
    private function get_form($html)
    {
        $form = null;
        if (isset($this->config['global']['form_name'])) {
            $form = $html->find('form[name=' . $this->config['global']['form_name'] . ']', 0);
        }
        if ($form === null) {
            // form_name設定が空もしくは、該当のformが無い場合は一つ目のformを対象とする。
            $form = $html->find('form', 0);
        }
        if ($form === null) {
            $this->error_screen($this->message_config['message']['msg_form_not_found']);
        }

        return $form;
    }

    /**
     * formに値をセットする
     * @param simple_html_dom $form
     */
    private function set_form($form)
    {
        foreach ($this->form_item as $name => $item) {
            // フォーム項目名にサフィックスを追加したID名を持つ要素をテンプレート内に用意しておく
            // エラー発生時はその要素の中にエラーメッセージを表示する
            // エラーが発生しなかったら、その要素を削除する
            $error_elem = $form->find('#' . $name . self::ERROR_ID_SUFFIX, 0);
            if ($error_elem !== null && isset($item['error']) && strlen($item['error']) > 0) {
                $error_elem->innertext = $item['error'];

                // エラー発生時に親要素にクラスを適用する
                $parents = null;
                if (isset($item['error_parents'])) {
                    $parents = $item['error_parents'];
                } else if (isset($this->config['validation']['error_parents'])) {
                    $parents = $this->config['validation']['error_parents'];
                }
                if ($parents) {
                    $error_class = $this->config['validation']['error_class'];
                    for ($i = 0; $i < $parents; $i++) {
                        $error_elem = $error_elem->parent();
                    }
                    $error_elem->class = $error_elem->class . ' ' . $error_class;
                }
            } else if ($error_elem !== null) {
                $error_elem->outertext = '';
            }

            // エラー発生時もしくは、戻るボタンが押された場合は、INPUT要素の値を書き換える。
            // 初回アクセス時は、書き換えないのでテンプレートの状態が初期値となる。
            if (!isset($item['value'])) {
                continue;
            }

            switch ($item['type']) {
                case 'textarea':
                    $form->find('textarea[name=' . $name . ']', 0)->innertext = $this->html_escape($item['value']);
                    break;
                case 'select':
                    if (isset($item['multiple']) && $item['multiple']) {
                        $option_list = $form->find('select[name=' . $name . '[]]', 0)->find('option');
                        foreach ($option_list as $option) {
                            $selected = null;
                            if (is_array($item['value'])) {
                                foreach ($item['value'] as $val) {
                                    if ($option->value === $val) {
                                        $selected = (strlen($val) > 0) ? 'selected' : null;
                                        continue;
                                    }
                                }
                            }
                            $option->selected = $selected;
                        }
                    } else {
                        $option_elem = $form->find('select[name=' . $name . ']', 0)->find('option[selected], option[selected=selected]', 0);
                        if ($option_elem !== null) {
                            $option_elem->selected = null;
                        }
                        $form->find('select[name=' . $name . ']', 0)->find('option[value=' . $item['value'] . ']', 0)->selected = 'selected';
                    }
                    break;
                case 'radio':
                    foreach ($form->find('input[name=' . $name . ']') as $radio) {
                        if ($radio->value === $item['value']) {
                            $radio->checked = 'checked';
                        } else if ($radio->checked !== null) {
                            $radio->checked = null;
                        }
                    }
                    break;
                case 'checkbox':
                    if (isset($item['multiple']) && $item['multiple']) {
                        $checkbox_list = $form->find('input[name=' . $name . '[]]');
                        foreach ($checkbox_list as $checkbox) {
                            $checked = null;
                            if (is_array($item['value'])) {
                                foreach ($item['value'] as $val) {
                                    if ($checkbox->value === $val) {
                                        $checked = (strlen($val) > 0) ? 'checked' : null;
                                        continue;
                                    }
                                }
                            }
                            $checkbox->checked = $checked;
                        }
                    } else {
                        $checked = ($this->item_has_value($item)) ? 'checked' : null;
                        $form->find('input[name=' . $name . ']', 0)->checked = $checked;
                    }
                    break;
                case 'captcha':
                case 'price':
                    // 初期状態のままにする。
                    break;
                case 'file':
                    if ($this->item_has_single_value($item)) {
                        $add_html = '<input type="hidden" name="' . $name . '" value="'
                                . $this->html_escape($item['value']) . '"' . $this->self_closing_tag() . '>'
                                . $this->html_escape($item['value']) . '<br>';
                        $file_elem = $form->find('input[name=' . $name . ']', 0);
                        $file_elem->required = null;
                        $file_elem->outertext = $add_html . $file_elem->outertext;
                    }
                    break;
                default:
                    $form->find('input[name=' . $name . ']', 0)->value = $this->html_escape($item['value']);
                    break;
            }
        }
    }

    /**
     * 文字コード変換
     * 必要に応じて文字コード変換をして文字列を返す
     * @param string $str
     * @return string $str
     */
    private function convert_encoding($str)
    {
        if (is_array($str)) {
            return array_map(array('Me_MailForm', 'convert_encoding'), $str);
        } else if (is_object($str) || is_null($str)) {
            return '';
        }

        if ($this->convert_char_code) {
            $str = mb_convert_encoding($str, self::SYSTEM_CHAR_CODE, $this->config['global']['char_code']);
        }
        return $str;
    }

    /**
     * 次のステップ名を返す
     * @return string $next_step
     */
    private function get_next_step()
    {
        $next_step = self::SEND;
        if ($this->step === self::ENTRY && $this->config['flow']['use_confirm']) {
            $next_step = self::CONFIRM;
        }
        return $next_step;
    }

    /**
     * 各アイテムに値をセットする
     * @param simple_html_dom $html
     */
    private function output_item_value($html)
    {
        foreach ($this->form_item as $name => $item) {
            $item_elem = $html->find('#' . $name, 0);
            if ($item_elem === null) {
                continue;
            }
            // input系のタグは除外する
            $exclude_tags = array('input', 'textarea', 'select');
            if (array_search($item_elem->tag, $exclude_tags) !== false) {
                continue;
            }
            $value = isset($item['value']) ? $item['value'] : '';
            switch ($item['type']) {
                case 'textarea':
                    $item_elem->innertext = nl2br($this->html_escape($value));
                    break;
                case 'select':
                    $item_value = $value;
                    if (isset($item['multiple']) && $item['multiple'] && is_array($value)) {
                        $item_value = implode($this->config['select']['delimiter'], $value);
                    }
                    $item_elem->innertext = nl2br($this->html_escape($item_value));
                    break;
                case 'checkbox':
                    $item_value = $value;
                    if (isset($item['multiple']) && $item['multiple'] && is_array($value)) {
                        $item_value = implode($this->config['checkbox']['delimiter'], $value);
                    }
                    $item_elem->innertext = nl2br($this->html_escape($item_value));
                    break;
                case 'price':
                    $item_elem->innertext = nl2br($this->html_escape(number_format($value)));
                    break;
                default:
                    $item_elem->innertext = $this->html_escape($value);
                    break;
            }
       }
    }

    /**
     * 全アイテムのhiddenタグを生成する
     * @return string $hidden
     */
    private function get_hidden_tags()
    {
        // ステップパラメータを取得
        $hidden = $this->get_hidden_tag(self::STEP_PARAMETER, $this->get_next_step());

        foreach ($this->form_item as $name => $item) {
            if (($item['type'] === 'select' || $item['type'] === 'checkbox')
                && isset($item['multiple']) && $item['multiple'] && isset($item['value']) && is_array($item['value'])) {
                foreach ($item['value'] as $value) {
                    $hidden .= $this->get_hidden_tag($name . '[]', $value);
                }
            } else {
                $value = isset($item['value']) ? $item['value'] : '';
                $hidden .= $this->get_hidden_tag($name, $value);
            }
        }
        return $hidden;
    }

    /**
     * hiddenタグを生成する
     * @param string $name
     * @param string $value
     * @return string
     */
    private function get_hidden_tag($name, $value)
    {
        return '<input type="hidden" name="' . $name . '" value="'
                . $this->html_escape($value) . '"' . $this->self_closing_tag() . '>';
    }

    /**
     * メール送信処理
     */
    private function send_mail()
    {
        require_once('qdmail.php');
        require_once('qdsmtp.php');

        // qdmailオブジェクト作成
        $mail = $this->get_qdmail();

        // toアドレス
        $to = $this->mail_config['mail']['to'];

        // 送信先振り分け
        if (isset($this->mail_config['sorting']['item_name'])) {
            $value_key = array_search($this->form_item[$this->mail_config['sorting']['item_name']]['value'],
                $this->mail_config['sorting']);
            $email_key = str_replace('value', 'email', $value_key);
            $to_address = $this->mail_config['sorting'][$email_key];
            if ($to_address) {
                $to = $to_address;
            }
        }

        // csv保存用にアイテムとしてセットする
        $this->form_item['_to'] = array('type'=>'reserved','label'=>'To','value'=>$to);
        $cc = (isset($this->mail_config['mail']['cc'])) ? $this->mail_config['mail']['cc'] : null;
        $this->form_item['_cc'] = array('type'=>'reserved','label'=>'Cc','value'=>$cc);
        $bcc = (isset($this->mail_config['mail']['bcc'])) ? $this->mail_config['mail']['bcc']: null;
        $this->form_item['_bcc'] = array('type'=>'reserved','label'=>'Bcc','value'=>$bcc);

        // fromアドレス
        $from = array($this->mail_config['mail']['from'], $this->mail_config['mail']['from_name']);

        // Return-Path指定
        if (isset($this->mail_config['mail']['return_path'])) {
            $mail->mtaOption('-f ' . $this->mail_config['mail']['return_path']);
        }

        // Reply-toアドレス
        if (isset($this->mail_config['mail']['reply_to'])) {
            // 固定の Reply-to アドレスを指定
            $mail->replyto($this->mail_config['mail']['reply_to']);
        } else if (isset($this->mail_config['mail']['reply_to_item'])) {
            // フォーム入力値を Reply-to アドレスに指定
            $reply_to_item = $this->mail_config['mail']['reply_to_item'];
            $mail->replyto($this->form_item[$reply_to_item]['value']);
        }

        // サブジェクト取得
        $subject = $this->replace_text($this->mail_config['mail']['subject'], true);
        // メール本文取得
        $body = $this->get_body(self::BODY_FILE);

        // CC/BCC 指定
        $mail->to($this->multi_address($this->remove_line_break($to)));
        if (isset($this->mail_config['mail']['cc'])) {
            $mail->cc($this->multi_address($this->mail_config['mail']['cc']));
        }
        if (isset($this->mail_config['mail']['bcc'])) {
            $mail->bcc($this->multi_address($this->mail_config['mail']['bcc']));
        }
        $mail->from($this->remove_line_break($from));
        $mail->subject($this->remove_line_break($subject));
        $mail->text($this->autoLineFeed($body));

        // 添付ファイル
        $attaches = array();

        // CSV添付
        if (isset($this->config['csv']['csv_attach']) && !is_null($this->config['csv']['csv_attach'])) {
            $csv_tmpfile = tmpfile();
            $meta_data = stream_get_meta_data($csv_tmpfile);
            $tmp_filename = $meta_data['uri'];
            $this->attach_csv($tmp_filename);
            $attaches[] = array($tmp_filename , $this->replace_text($this->config['csv']['csv_attach'], true));
        }

        // メール添付設定確認
        if (!empty($this->config['upload_file']['attach_mail'])) {
            $tmpfile_list = array();
            foreach ($this->form_item as $name =>  $item) {
                if ($item['type'] === 'file' && !empty($item['value']) && isset($_SESSION['attach_files'][$name]) && isset($_SESSION['attach_file_names'][$name])) {
                    $tmpfile_list[$name] = tmpfile();
                    fwrite($tmpfile_list[$name], $_SESSION['attach_files'][$name]);
                    $meta_data = stream_get_meta_data($tmpfile_list[$name]);
                    $tmp_filename = $meta_data['uri'];
                    $attaches[] = array($tmp_filename , $_SESSION['attach_file_names'][$name]);
                }
            }
        }
        if (!empty($attaches)) {
            $mail->attach($attaches);
        }

        // メール送信
        if (!$mail->send()) {
            $this->error_screen($this->message_config['message']['msg_send']);
        }

        // 自動返信メール処理
        if (isset($this->mail_config['reply_mail']['to_item'])) {
            // 自動返信メールでは管理者宛と異なるメッセージIDが生成されるようにする
            unset($mail->other_header['Message-Id']);
            $mail->salt = 'reply_mail';

            // 添付ファイルは添付しない。
            $mail->attach = array();
            $mail->attach_path = null;
            $mail->attach_already_build = false;

            // CC / BCC / Reply-to をクリア
            $mail->cc = array();
            $mail->bcc = array();
            $mail->replyto = array();

            $to_item = $this->mail_config['reply_mail']['to_item'];
            $to = $this->form_item[$to_item]['value'];
            if (isset($this->mail_config['reply_mail']['from'])) {
                if (isset($this->mail_config['reply_mail']['from_name'])) {
                    $from = array($this->mail_config['reply_mail']['from'], $this->mail_config['reply_mail']['from_name']);
                } else {
                    $from = $this->mail_config['reply_mail']['from'];
                }
                $mail->from($this->remove_line_break($from));
            }
            // Reply-toアドレス
            if (isset($this->mail_config['reply_mail']['reply_to'])) {
                // 固定の Reply-to アドレスを指定
                $mail->replyto($this->mail_config['reply_mail']['reply_to']);
            }
            if (isset($this->mail_config['reply_mail']['subject'])) {
                // サブジェクト取得
                $subject = $this->replace_text($this->mail_config['reply_mail']['subject'], true);
            }
            // 自動返信用本文ファイルがあれば使う
            if (file_exists(DATA_ROOT . self::REPLY_BODY_FILE . '.txt')
                || file_exists(DATA_ROOT . self::REPLY_BODY_FILE . '.php')) {
                // メール本文取得
                $body = $this->get_body(self::REPLY_BODY_FILE);
                $body = $this->autoLineFeed($body);
            }
            if (strlen($to) > 0) {
                $mail->to($this->remove_line_break($to));
                if (isset($this->mail_config['reply_mail']['cc'])) {
                    $mail->cc($this->multi_address($this->mail_config['reply_mail']['cc']));
                }
                if (isset($this->mail_config['reply_mail']['bcc'])) {
                    $mail->bcc($this->multi_address($this->mail_config['reply_mail']['bcc']));
                }
                $mail->subject($this->remove_line_break($subject));
                $mail->text($body);
                // メール送信
                if (!$mail->send()) {
                    $this->error_screen($this->message_config['message']['msg_send']);
                }
            }
        }
    }

    /**
     * 本文を取得する
     * @param string $path
     * @return string $body
     */
    private function get_body($path)
    {
        $txt_file_path = DATA_ROOT . $path . '.txt';
        $php_file_path = DATA_ROOT . $path . '.php';

        if (file_exists($php_file_path)) {
            // $item に form_item のvalueだけの配列にする。
            $item = array();
            foreach ($this->form_item as $key => $array) {
                $item[$key] = $array['value'];
            }

            // バッファリング制御
            ob_start();
            include($php_file_path);
            $body = ob_get_contents();
            //バッファを削除
            ob_end_clean();
        } else {
            $body = $this->replace_text(file_get_contents($txt_file_path), false);
        }
        return $body;
    }

    /**
     * 基本設定を行った状態のqdmailオブジェクトを返す
     * @return Qdmail $mail
     */
    private function get_qdmail()
    {
        $mail = new Qdmail();
        $charset = (isset($this->mail_config['mail']['charset'])) ? $this->mail_config['mail']['charset'] : self::MAIL_CHARSET;
        $encoding = (isset($this->mail_config['mail']['encoding'])) ? $this->mail_config['mail']['encoding'] : self::MAIL_ENCODING;
        $mail->charset($charset, $encoding);

        // エラー表示制御
        $mail->error_display = $this->mail_config['library']['error_display'];

        // smtpセクションがあればSMTPサーバーを経由して送信する
        if (!empty($this->mail_config['smtp'])) {
            $param = array();
            switch ($this->mail_config['smtp']['protocol']) {
                case 'POP_BEFORE':
                    $param['pop_host'] = $this->mail_config['smtp']['pop_host'];
                case 'SMTP_AUTH':
                    $param['user'] = $this->mail_config['smtp']['user'];
                    $param['pass'] = $this->mail_config['smtp']['password'];
                default:
                    $param['host'] = $this->mail_config['smtp']['host'];
                    $param['port'] = $this->mail_config['smtp']['port'];
                    $param['from'] = $this->mail_config['mail']['from'];
                    $param['protocol'] = $this->mail_config['smtp']['protocol'];
                    break;
            }
            $mail->smtp(true);
            $mail->smtpServer($param);
        }

        // ログ
        if ($this->mail_config['library']['log_level']) {
            $mail->logLevel($this->mail_config['library']['log_level']);
            $mail->logPath(DATA_ROOT . self::MAIL_LOG_DIR);
            $mail->logFilename(self::MAIL_LOG_FILENAME);
        }
        // エラーログ
        if ($this->mail_config['library']['error_log_level']) {
            $mail->errorlogLevel($this->mail_config['library']['error_log_level']);
            $mail->errorlogPath(DATA_ROOT . self::MAIL_LOG_DIR);
            $mail->errorlogFilename(self::MAIL_ERROR_LOG_FILENAME);
        }
        // 改行コード設定
        if (isset($this->mail_config['library']['line_feed'])) {
            $line_feed_setting = strtolower($this->mail_config['library']['line_feed']);
            if ($line_feed_setting === 'lf') {
                $mail->lineFeed("\n");
            } else if ($line_feed_setting === 'crlf') {
                $mail->lineFeed("\r\n");
            }
        }

        return $mail;
    }

    /**
     * データベースハンドル生成
     * @param string $db_name DB名
     * @return object $pdo_instance
     */
    private function get_pdo_instance($db_name = 'zipcode')
    {
        $dsn = 'sqlite:' . DATA_ROOT . '/db/' . $db_name . '.sqlite';
        try {
            $pdo_instance = new PDO($dsn);
            $pdo_instance->beginTransaction();
            $pdo_instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            $this->error_screen('Exception: ' . $e->getMessage());
        } catch (Exception $e) {
            $this->error_screen('Exception: ' . $e->getMessage());
        }
        return $pdo_instance;
    }

    /**
     * 通し番号発行
     */
    private function issue_serial()
    {
        if (!$this->config['serial']['serial']) {
            return;
        }

        try {
            // レコード登録
            $con = $this->get_pdo_instance('serial');
            $sql = 'insert into serial (create_date) values (:create_date);';
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':create_date', date('Y-m-d H:i:s', $this->now));
            $stmt->execute();

            // シリアル値取得
            $sql = 'select last_insert_rowid();';
            $stmt = $con->prepare($sql);
            $stmt->execute();
            $serial_no = (int) $stmt->fetchColumn();
            // コミット前にカーソルを閉じる
            $stmt->closeCursor();

            // コミット
            $con->commit();
        } catch (PDOException $e) {
            $this->error_screen('Exception: ' . $e->getMessage());
        } catch (Exception $e) {
            $this->error_screen('Exception: ' . $e->getMessage());
        }

        // オフセット指定
        if (isset($this->config['serial']['serial_offset'])) {
            $serial_no += $this->config['serial']['serial_offset'];
        }

        // 最大値指定
        if (isset($this->config['serial']['serial_max'])) {
            $serial_no = $serial_no % (int) $this->config['serial']['serial_max'];
        }

        // フォーマット
        if (isset($this->config['serial']['serial_format'])) {
            $serial_no = sprintf($this->config['serial']['serial_format'], $serial_no);
        }

        // 日付フォーマット
        if (isset($this->config['serial']['serial_date_format'])) {
            $serial_no = str_replace('{_date}', date(date($this->config['serial']['serial_date_format'], $this->now)), $serial_no);
        }

        // form_itemに保存
        $this->form_item['_serial'] = array(
            'type' => 'reserved',
            'label' => $this->config['serial']['label'],
            'value' => $serial_no,
        );
    }

    /**
     * 連続送信確認
     */
    private function check_limitation()
    {
        // max_sends が 0 の場合は（1より小さい場合）チェックしない
        if ($this->config['security']['max_sends'] < 1) {
            return;
        }
        // limitation.sqlite ファイルが無いか、書き込みできない場合はチェックしない。
        $sqlite_file = DATA_ROOT . '/db/limitation.sqlite';
        if (!file_exists($sqlite_file) || !is_writable($sqlite_file)) {
            return;
        }

        $max_sends = $this->config['security']['max_sends'];
        $ip = $this->form_item['_ip']['value'];
        // send_period は分なので、60を掛けて秒数にする。
        $send_period_sec = $this->config['security']['send_period'] * 60;
        $begin_date = date('Y-m-d H:i:s', $this->now - $send_period_sec);
        $now_date = date('Y-m-d H:i:s', $this->now);

        try {
            $con = $this->get_pdo_instance('limitation');

            // 設定時間内の送信回数を取得
            $sql = "select count(*) from limitation where ip = :ip and action_type = 'send' and :begin_date <= action_date and action_date <= :now_date;";
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':ip', $ip);
            $stmt->bindValue(':begin_date', $begin_date);
            $stmt->bindValue(':now_date', $now_date);
            $stmt->execute();
            $send_count = (int) $stmt->fetchColumn();

            // コミット前にカーソルを閉じる
            $stmt->closeCursor();
        } catch (PDOException $e) {
            $this->error_screen('Exception: ' . $e->getMessage());
        } catch (Exception $e) {
            $this->error_screen('Exception: ' . $e->getMessage());
        }

        // 前回までの送信数に、今回の送信数（予定）を追加する。
        $send_count++;
        // 送信数が、最大値設定より大きい場合はエラー
        if ($send_count > $max_sends) {
            $this->error_screen($this->message_config['message']['msg_max_sends']);
        }

        try {
            // レコード登録
            $sql = "insert into limitation (ip, action_type, action_date) values (:ip, 'send', :action_date);";
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':ip', $ip);
            $stmt->bindValue(':action_date', $now_date);
            $stmt->execute();

            // 期限外の旧レコードを削除
            $sql = "delete from limitation where action_type = 'send' and :begin_date >= action_date";
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':begin_date', $begin_date);
            $stmt->execute();

            // コミット
            $con->commit();
        } catch (PDOException $e) {
            $this->error_screen('Exception: ' . $e->getMessage());
        } catch (Exception $e) {
            $this->error_screen('Exception: ' . $e->getMessage());
        }
    }

    /**
     * 添付ファイルをアップロードディレクトリに保存する
     */
    private function save_file()
    {
        // サーバー保存設定確認
        if (empty($this->config['upload_file']['save_file'])) {
            return;
        }

        foreach ($this->form_item as $name =>  $item) {
            if ($item['type'] === 'file' && !empty($item['value']) && isset($_SESSION['attach_files'][$name]) && isset($_SESSION['attach_file_names'][$name])) {
                // ファイル名をセッションから取り出す
                $filename = $_SESSION['attach_file_names'][$name];

                // アップロードパス
                if ($this->config['upload_file']['upload_file_path']) {
                    $upload_file_path = $this->config['upload_file']['upload_file_path'];
                } else {
                    $upload_file_path = '{random}/{filename}';
                }

                // 乱数置換 {random}
                if (strpos($upload_file_path, '{random}') !== false) {
                    do {
                        $random = $this->generateRandomString();
                        $random_dir = DATA_ROOT . '/uploads/' . $random;
                    } while (file_exists($random_dir));
                    $upload_file_path = str_replace('{random}', $random, $upload_file_path);
                }
                // ファイル名置換 {filename}
                $upload_file_path = str_replace('{filename}', $filename, $upload_file_path);
                // 拡張子置換 {ext}
                $extension = pathinfo($filename, PATHINFO_EXTENSION);
                $upload_file_path = str_replace('{ext}', $extension, $upload_file_path);
                // アイテム名置換 {item_name}
                $upload_file_path = str_replace('{item_name}', $name, $upload_file_path);
                // シリアル値を置換 {_serial}
                if (isset($this->form_item['_serial'])) {
                    $upload_file_path = str_replace('{_serial}', $this->form_item['_serial']['value'], $upload_file_path);
                }

                $upload_path = DATA_ROOT . '/uploads/' . $upload_file_path;

                // アップロードファイルパスをURLエンコードする
                $pieces = explode('/', $upload_file_path);
                foreach ($pieces as &$piece) {
                    $piece = rawurlencode($piece);
                }
                $upload_file_path = implode('/', $pieces);

                // ダウンロード用URL作成
                $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER["REQUEST_URI"]) . '/me_mailform/data/uploads/' . $upload_file_path;

                // form_itemに保存
                $this->form_item[$name . '_url'] = array(
                    'type' => 'reserved',
                    'label' => $item['label'] . ' (URL)',
                    'value' => $url,
                );

                // Windows で PHP 7.1 未満はファイルパスの文字コードを変換する
                if (PHP_VERSION_ID < 70100 && (PHP_OS === 'WINNT' || PHP_OS === 'WIN32')) {
                    $upload_path = mb_convert_encoding($upload_path, 'SJIS-win', 'UTF-8');
                }

                // 保存先のディレクトリがなければ作成
                if (!is_dir(dirname($upload_path))) {
                    $dir_path = dirname($upload_path);
                    mkdir($dir_path);
                }
                // session内のファイルをアップロードディレクトリに書き出す
                file_put_contents($upload_path, $_SESSION['attach_files'][$name]);
            }
        }
    }

    /**
     * 送信者情報をform_itemに保存
     */
    private function set_sender_info()
    {
        // _date のラベルは item.ini で指定されていれば採用する
        $date_label = isset($this->form_item['_date']['label']) ? $this->form_item['_date']['label'] : 'Date';
        $this->form_item['_date'] = array('type'=>'reserved','label'=>$date_label,'value'=>date($this->config['Date']['date_format'], $this->now));

        // クライアントIPを取得する
        $client_ip_config = $this->config['security']['client_ip'];
        if (isset($_SERVER[$client_ip_config])) {
            $ips = $this->str_to_array($_SERVER[$client_ip_config]);
            if (isset($ips[0])) {
                $ip = $ips[0];
            } else {
                $ip = $_SERVER['REMOTE_ADDR'];
            }
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        // 各ラベルは item.ini で指定されていれば採用する
        $ip_label = isset($this->form_item['_ip']['label']) ? $this->form_item['_ip']['label'] : 'IP';
        $this->form_item['_ip'] = array('type'=>'reserved','label'=>$ip_label,'value'=>$ip);
        $host_label = isset($this->form_item['_host']['label']) ? $this->form_item['_host']['label'] : 'Host';
        $this->form_item['_host'] = array('type'=>'reserved','label'=>$host_label,'value'=>gethostbyaddr($ip));
        $ua_label = isset($this->form_item['_ua']['label']) ? $this->form_item['_ua']['label'] : 'User Agent';
        $this->form_item['_ua'] = array('type'=>'reserved','label'=>$ua_label,'value'=>$_SERVER['HTTP_USER_AGENT']);
    }

    /**
     * CSV保存
     * @return boolean
     */
    private function save_csv()
    {
        // CSVファイル名の指定が無ければ保存しない。
        if (!isset($this->config['csv']['csv_file']) || is_null($this->config['csv']['csv_file'])) {
            return true;
        }
        // CSVファイルパス
        $file_path = DATA_ROOT . self::CSV_SAVE_DIR . $this->config['csv']['csv_file'];

        // 書き込みできることを確認
        if (!$this->writable_file($file_path)) {
            $this->error_screen('CSVファイルの書き込み権限がありません。');
        }

        // BOM出力フラグ
        $with_bom = false;
        // ヘッダー出力フラグ
        $output_header = false;

        // 初回はヘッダーを作成
        if (!file_exists($file_path)) {
            // BOMが指定されてUTF-8の場合は、先頭にBOMを追加する。
            if ($this->config['csv']['bom'] && strtoupper($this->config['csv']['char_code']) === 'UTF-8') {
                $with_bom = true;
            }
            // ファイルが存在しなければ、先頭行にヘッダーを出力する
            $output_header = true;
        }
        // CSVデータを生成
        $csv_lines = $this->generate_csv($output_header);

        // CSVファイルを保存する
        $this->write_csv($file_path, $csv_lines, $with_bom);

        return true;
    }

    /**
     * CSV添付
     * @param $tmp_filename
     */
    private function attach_csv($tmp_filename)
    {
        // BOM出力フラグ
        $with_bom = false;

        // BOMが指定されてUTF-8の場合は、先頭にBOMを追加する。
        if ($this->config['csv']['bom'] && strtoupper($this->config['csv']['char_code']) === 'UTF-8') {
            $with_bom = true;
        }

        // CSVデータを生成
        $csv_lines = $this->generate_csv(true);

        // CSVファイルを保存する
        $this->write_csv($tmp_filename, $csv_lines, $with_bom);
    }

    /**
     * CSVデータ生成
     * @param bool $output_header
     * @return array
     */
    private function generate_csv($output_header = true)
    {
        $csv_lines = array();
        $form_item = $this->form_item;

        // 保存アイテムリストが指定されている場合
        if (isset($this->config['csv']['item_list'])) {
            $tmp_form_item = array();
            $item_list = $this->str_to_array($this->config['csv']['item_list']);
            foreach ($item_list as $name) {
                $tmp_form_item[$name] = $form_item[$name];
            }
            $form_item = $tmp_form_item;
        }

        // 文字コード設定
        if (empty($this->config['csv']['char_code'])) {
            $this->config['csv']['char_code'] = self::CSV_CHAR_CODE;
        }

        // ヘッダー出力が指定されている場合のみ、ヘッダー行を生成する
        if ($output_header) {
            $csv_header = array();
            foreach ($form_item as $item) {
                $csv_header[] = $item['label'];
            }
            $csv_lines[] = $csv_header;
        }

        // データ行を作成
        $csv = array();
        foreach ($form_item as $name => $item) {
            $value = $item['value'];
            if ($item['type'] === 'checkbox' && is_array($value)) {
                $value = implode($this->config['checkbox']['delimiter'], $value);
            } else if ($item['type'] === 'select' && is_array($value)) {
                $value = implode($this->config['select']['delimiter'], $value);
            }
            $csv[] = $value;
        }
        $csv_lines[] = $csv;

        return $csv_lines;
    }

    /**
     * ファイルの書き込み権限を確認
     * @param string $file_path
     * @return boolean
     */
    private function writable_file($file_path)
    {
        $exist = file_exists($file_path);
        if ($exist) {
            // ファイルがあれば、そのファイルの書き込み権限を確認
            if (!is_writable($file_path)) {
                return false;
            }
        } else {
            // ファイルがなければ、ディレクトリの書き込み権限を確認
            if (!is_writable(dirname($file_path))) {
                return false;
            }
        }
        return true;
    }

    /**
     * CSVファイル書き出し
     * @param string $file_path
     * @param array $csv_lines
     * @param boolean $with_bom
     */
    private function write_csv($file_path, $csv_lines, $with_bom = false)
    {
        $handle = fopen($file_path, 'a');
		if (flock($handle, LOCK_EX)) {
            if ($with_bom) {
                fwrite($handle, "\xEF\xBB\xBF");
            }
            foreach ($csv_lines as $csv) {
                if (strtoupper($this->config['csv']['char_code']) !== self::SYSTEM_CHAR_CODE) {
                    mb_convert_variables($this->config['csv']['char_code'], self::SYSTEM_CHAR_CODE, $csv);
                }
                fputcsv($handle, $csv);
            }
		}
		flock($handle,LOCK_UN);
		fclose($handle);
    }

    /**
     * エラー画面出力
     * @param string $message エラーメッセージ
     */
    private function error_screen($message)
    {
        // テンプレートを取得
        $html = $this->get_template($this->config['step']['error']);
        $html->find('#' . $this->message_config['message']['error_message_id'], 0)->outertext = $this->html_escape($message);

        // HTML書き出し
        $this->render($html);

        // 処理終了
        die;
    }

    /**
     * HTML書き出し
     * @param simple_html_dom $html
     */
    private function render($html)
    {
        include_once 'Me_Guard.php';
        Me_Guard::render($html, $this->convert_char_code, $this->config['global']['char_code'], self::SYSTEM_CHAR_CODE);
    }

    /**
     * メール本文に入力値を置換する
     * @param string $str
     * @param boolean $subject_mode
     * @return string $str
     */
    private function replace_text($str, $subject_mode = false)
    {
        foreach (array_reverse($this->form_item) as $name => $item) {
            if ($subject_mode && $item['type'] === 'textarea') {
                continue;
            }
            $value = $item['value'];
            if ($item['type'] === 'checkbox' && is_array($value)) {
                $value = implode($this->config['checkbox']['delimiter'], $value);
            } else if ($item['type'] === 'select' && is_array($value)) {
                $value = implode($this->config['select']['delimiter'], $value);
            }
            if ($item['type'] === 'price') {
                $value = number_format($value);
            }
            if (is_null($value)) {
                $value = '';
            }
            $str = str_replace('{' . $name . '}', $value, $str);
        }
        return $str;
    }

    /**
     * item.iniもしくはmessage.iniからエラーメッセージを取得して$itemにセットする
     * @param array & $item
     * @param string $key
     * @param array $replace
     */
    private function set_error_message(&$item, $key, $replace = array())
    {
        if (isset($item[$key]) && strlen($item[$key]) > 0) {
            // item固有のエラーメッセージを持っている場合
            $item['error'] = $item[$key];
        } else {
            // 共通エラーメッセージ
            $item['error'] = $this->message_config['message'][$key];
        }
        // 置換処理
        foreach ($replace as $search_key => $value) {
            $item['error'] = str_replace($search_key, $value, $item['error']);
        }
    }

    /**
     * トークンを生成してinput要素を返す
     */
    private function get_token()
    {
        if (!isset($_SESSION['_token'])) {
            $_SESSION['_token'] = sha1(session_id() . microtime());
        }
        return $this->get_hidden_tag('_token', $this->html_escape($_SESSION['_token']));
    }

    /**
     * html特殊文字をエンティティ化する
     * @param string $str エンティティ化対象文字列
     * @return string 特殊文字をエンティティ化した文字列
     */
    private function html_escape($str)
    {
        return htmlspecialchars($str, ENT_QUOTES, self::SYSTEM_CHAR_CODE);
    }

    /**
     * xhtmlの場合は " /" を返す
     * @return string
     */
    private function self_closing_tag()
    {
        return (isset($this->config['global']['xhtml']) && $this->config['global']['xhtml']) ? ' /' : '';
    }

    /**
     * カンマ区切りの文字列を分割して配列で返す
     * @param string $str
     * @param string $delimiter
     * @return array $value_list
     */
    private function str_to_array($str, $delimiter = ',')
    {
        $value_list = array();
        foreach (explode($delimiter, $str) as $value) {
            if (strlen($value) > 0) {
                $value_list[] = trim($value);
            }
        }
        return $value_list;
    }

    /**
     * 複数のtoアドレスを指定できるqdmail用の形式に変換する
     * @param string $config_value
     * @return array $address_list
     */
    private function multi_address($config_value)
    {
        $address_list = array();
        foreach (explode(',', $config_value) as $value) {
            if (strlen($value) > 0) {
                $address_list[] = array(trim($value), '');
            }
        }
        return $address_list;
    }

    /**
     * 改行文字を取り除く
     * @param string|array $value
     * @return string
     */
    private function remove_line_break($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = preg_replace('/\r|\n/u', '', $item);
            }
        } else {
            $value = preg_replace('/\r|\n/u', '', $value);
        }
        return $value;
    }

    /**
     * 自動改行処理
     * @param string $body
     * @return string
     */
    private function autoLineFeed($body)
    {
        if (!isset($this->mail_config['mail']['line_length'])) {
            return $body;
        }

        // 文字コード取得
        $charset = (isset($this->mail_config['mail']['charset'])) ? $this->mail_config['mail']['charset'] : self::MAIL_CHARSET;
        if ($charset === 'ISO-2022-JP') {
            $charset = 'ISO-2022-JP-MS';
        }

        return $this->mb_chunk_split($body, $this->mail_config['mail']['line_length'], "\n", $charset);
    }

    /**
     * 文字列をより小さな部分に分割する
     * マルチバイトに対応した chunk_split() 関数。
     * $body はUTF-8で渡して、文字数を計算するときの文字コードは別途 $encoding で指定する。
     * @param string $body UTF-8の文字列
     * @param int $chunklen バイト数
     * @param string $end
     * @param string $encoding 評価する文字コード
     * @return string UTF-8の文字列
     */
    private function mb_chunk_split($body, $chunklen = 998, $end = "\n", $encoding = 'UTF-8')
    {
        // 改行コードで分割して配列に格納する
        $lines = preg_split('/\r\n|\r|\n/u', $body);
        $new_lines = array();

        foreach($lines as $line) {
            do {
                if ($encoding === 'UTF-8') {
                    $target_line = $line;
                } else {
                    // UTF-8以外の場合は指定の文字コードに変換する
                    $target_line = mb_convert_encoding($line, $encoding, 'UTF-8');
                }

                // バイト数指定で文字列を切り出す
                $new_line = mb_strcut($target_line, 0, $chunklen, $encoding);
                if ($encoding !== 'UTF-8') {
                    // UTF-8以外の場合は切り出した文字列をUTF-8に戻す。
                    // ISO-2022-JPなどはエスケープシーケンスにより、切り出すことでバイト数が増えるため、UTF-8に戻している。
                    $new_line = mb_convert_encoding($new_line, 'UTF-8', $encoding);
                }
                // UTF-8の文字列で切り出した文字列以降を行としてセットする
                $line = mb_substr($line, mb_strlen($new_line));

                $new_lines[] = $new_line;
            } while (strlen($line) > 0);
        }

        return implode($end, $new_lines);
    }

    /**
     * 英数字の乱数生成
     * @param integer $length
     * @return string $ret
     */
    private function generateRandomString($length = 20) {
        $ret = '';
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charLength = strlen($chars);
        for ($i = 0; $i < $length; $i++) {
            $ret .= $chars[rand(0, $charLength - 1)];
        }
        return $ret;
    }
}
