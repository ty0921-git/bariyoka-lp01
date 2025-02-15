<?php
require("../core/config.php");
require("../function.php");
checkToken();
get_setting();

mb_language("ja");
mb_internal_encoding("utf-8");

// 入力値のバリデーション
if (empty($_POST['email']) || !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
  die("メールアドレスが不正です");
}

// コメントにひらがなが1文字でも含まれるか？
$intro = $_POST['intro'];
if ($intro !== "" && !preg_match('/[ぁ-ん]/u', $intro)) {
  die("自己紹介文にひらがなを含めてください");
}

// 入力値のサニタイズ
$post_data = array();
foreach ($_POST as $key => $val) {
  $post_data[$key] = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

// 半角変換
$post_data['email'] = mb_convert_kana($post_data['email'], "a", "utf-8");
$post_data['tel'] = mb_convert_kana($post_data['tel'], "a", "utf-8");


// 添付ファイルの保存
if (is_uploaded_file($_FILES['file']['tmp_name'])) {
  $file_name = $_FILES['file']['name'];
  $file_tmp = $_FILES['file']['tmp_name'];

  // 保存
  $file_name = mb_convert_kana($file_name, "a", "utf-8");
  $file_name = mb_convert_encoding($file_name, "ISO-2022-JP", "utf-8");

  // ファイル名にユニークな番号をつける
  $uniqid = uniqid();
  $file_name = $uniqid . "_" . $file_name;

  move_uploaded_file($file_tmp, "uploadfile/" . $file_name);
}



try {
  $pdo = connect();

  // メールテンプレート取得
  $sql = "SELECT * FROM mailtemp WHERE call_code = 'hougenjoshi' LIMIT 1";
  $stmt = $pdo->prepare($sql);
  $stmt->execute();
  $template = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$template) {
    throw new Exception('メールテンプレートが見つかりません');
  }

  // メール本文の構築
  $boundary = md5(uniqid(rand(), true));

  $header = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\n";
  $header .= "From: info@bariyoka.co.jp\n";
  $header .= "MIME-Version: 1.0\n";

  // メール本文
  $body = "--{$boundary}\n";
  $body .= "Content-Type: text/plain; charset=\"ISO-2022-JP\"\n";
  $body .= "Content-Transfer-Encoding: 7bit\n\n";

  // テンプレート変数の置換
  $mail_body = $template['mail_body'];
  $replace_pairs = array(
    '<name>' => $post_data['name'],
    '<sns>' => $post_data['sns'],
    '<sns_account>' => $post_data['sns_account'],
    '<birth>' => $post_data['birth'],
    '<area>' => $post_data['area'],
    '<genre>' => $post_data['genre'],
    '<intro>' => $post_data['intro'],
    '<tel>' => $post_data['tel'],
    '<email>' => $post_data['email'],
    '<sns_direct>' => $post_data['sns_direct'],
    '<file_code>' => $file_name
  );

  $mail_body = strtr($mail_body, $replace_pairs);

  // フッター取得と置換
  $sql = "SELECT * FROM mailtemp WHERE call_code = 'footer' LIMIT 1";
  $stmt = $pdo->prepare($sql);
  $stmt->execute();
  $footer = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($footer) {
    $footer_replace = array(
      '<site_name>' => $site_name,
      '<site_url>' => $site_url,
      '<com_name>' => $com_name,
      '<com_zip>' => $com_zip,
      '<com_add>' => $com_add,
      '<com_tel>' => $com_tel,
      '<com_fax>' => $com_fax
    );
    $mail_body .= "\n\n" . strtr($footer['mail_body'], $footer_replace);
  }

  $body .= $mail_body . "\n";

  // 添付ファイルの処理
  // if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
  //   $file_name = mb_encode_mimeheader($_FILES['file']['name']);
  //   $file_content = file_get_contents($_FILES['file']['tmp_name']);

  //   $body .= "--{$boundary}\n";
  //   $body .= "Content-Type: application/octet-stream; name=\"{$file_name}\"\n";
  //   $body .= "Content-Disposition: attachment; filename=\"{$file_name}\"\n";
  //   $body .= "Content-Transfer-Encoding: base64\n\n";
  //   $body .= chunk_split(base64_encode($file_content)) . "\n";
  // }

  $body .= "--{$boundary}--";

  // メール送信
  // ユーザーへの自動返信
  if (!mb_send_mail($post_data['email'], $template['mail_title'], $body, $header, '-f info@bariyoka.co.jp')) {
    throw new Exception('ユーザーへのメール送信に失敗しました');
  }

  // 管理者への通知
  $admin_header = "From: {$post_data['email']}\n";
  $admin_header .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\n";
  if (!mb_send_mail($com_email, $template['mail_title'], $body, $admin_header, "-f {$post_data['email']}")) {
    throw new Exception('管理者へのメール送信に失敗しました');
  }

  $_SESSION['msg'] = "
    <p>エントリーありがとうございます。</p>
    <p>自動返信にてご指定のアドレスへメールをお送りしました。</p>
    <p>内容を確認後、3営業日以内にご連絡致します。</p>
    ";

  header("Location: ../message.html");
  exit;
} catch (Exception $e) {
  error_log($e->getMessage());
  die('エラーが発生しました。しばらく経ってから再度お試しください。');
}
