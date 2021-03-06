<?php
/**
 * @author chomado <chomado@gmail.com>
 * @copyright 2015 by chomado <chomado@gmail.com>
 * @license https://github.com/chomado/chomado_bot/blob/master/LICENSE MIT
 */

use Abraham\TwitterOAuth\TwitterOAuth;
use chomado\bot\Chat;
use chomado\bot\Config;
use chomado\bot\Log;
use chomado\bot\RandomSentenceList;
use chomado\bot\TwitterUtil;
use chomado\bot\chat\ContextManager as ChatContextManager;

// bootstrap
require_once(__DIR__ . '/vendor/autoload.php');
Log::setErrorHandler();

$param = [];

// 最終投稿IDを取得
Log::trace("last_idを読み込みます。");
if (@file_exists(__DIR__ . '/runtime/last_id.txt')) {
    if ($since_id = file_get_contents(__DIR__ . '/runtime/last_id.txt')) {
        $param['since_id'] = $since_id;
        Log::info("since_id: {$since_id} を読み込みました。");
    } else {
        Log::warning(
            "last_id.txtからデータが読み込めません。空のパラメータが送信されます。"
        );
    }
    unset($since_id);
} else {
    Log::warning("last_id.txtがありません。空のパラメータが送信されます。");
}

// ファイルの行をランダムに抽出
$randomFaces = new RandomSentenceList(__DIR__ . '/tweet_content_data_list/face_list.txt');
Log::trace("face_listは" . count($randomFaces) . "行です");

// Twitterに接続
$config = Config::getInstance();
$connection = new TwitterOAuth(
    $config->getTwitterConsumerKey(),
    $config->getTwitterConsumerSecret(),
    $config->getTwitterAccessToken(),
    $config->getTwitterAccessTokenSecret()
);

// リプライを取得
Log::info("Twitter に問い合わせます。\nパラメータ:");
Log::info($param);
$res = $connection->get('statuses/mentions_timeline', $param);
if (!is_array($res)) {
    Log::error("Twitter から配列以外が返却されました:");
    Log::error($res);
    exit(1);
}
if (empty($res)) {
    Log::success("新着はありません");
    exit(0);
}

Log::success("Twitter からメンション一覧を取得しました。新着は " . count($res) . " 件です。");

// 最終投稿IDを書き込む
file_put_contents(__DIR__ . '/runtime/last_id.txt', $res[0]->id_str);
Log::trace("最終投稿IDを保存しました: " . $res[0]->id_str);

$success_count = 0;
$failure_count = 0;
$chat_context_manager = new ChatContextManager();

foreach ($res as $re) {
    $param = [];

    Log::info("届いたメッセージ:");
    Log::info(sprintf("    [@%s] %s - %s\n", $re->user->screen_name, $re->user->name, $re->text));

    // もし自分自身宛てだったら無視する.(無限ループになっちゃうから)
    if (strtolower($re->user->screen_name) === strtolower($config->getTwitterScreenName())) {
        Log::info("投稿ユーザが自分なので無視します");
        continue;
    }

    // 10分以上昔のツイートには反応しない
    if (strtotime($re->created_at) < time() - 600) {
        Log::info("投稿が古すぎるので無視します");
        continue;
    }

    // リプライ本文から余計なものを取り除く.
    // 例: "@chomado_bot こんにちは" → "こんにちは"
    $text = trim(preg_replace('/@[a-z0-9_]+/i', '', $re->text));

    // もし数字だけだったら素数判定処理をする
    if (filter_var($text, FILTER_VALIDATE_INT)) {
        $num = intval($text);
        $message = sprintf('%d の次の素数は %s です。', $num, '[そのうち実装するよ!]');
    } else {
        // botに来たリプライに数字以外のものが含まれていたら
        // 通常の雑談対話リプライをする
        $chat = new Chat(
            $config->getDocomoDialogueApiKey(),
            $chat_context_manager->getContextId($re->user->screen_name),
            $chat_context_manager->getMode($re->user->screen_name),
            $re->user->name,
            $text
        );
        $message = sprintf('%s %s%s', $chat->ResText(), $randomFaces->get(), PHP_EOL);
    }

    $param['status'] = sprintf(
        "@%s %sさん\n%s",
        $re->user->screen_name,
        trim(preg_replace('!([@＠#＃.]|://)!u', " $1 ", $re->user->name)),
        $message
    );

    $param['in_reply_to_status_id'] = $re->id_str;

    // 投稿
    if (TwitterUtil::postTweet($connection, $param)) {
        ++$success_count;
    } else {
        ++$failure_count;
    }

    $chat_context_manager->setContext(
        $re->user->screen_name,
        $chat->GetChatContextId(),
        $chat->GetChatMode()
    );
}

Log::log(
    sprintf("処理が完了しました: 成功 %d 件、失敗 %d 件", $success_count, $failure_count),
    $failure_count > 0 ? 'error' : 'success'
);

/**
 * エラーがあった時に私に知らせる
 */
if ($failure_count > 0) {
    $param = [];
    $param['status'] = sprintf(
        "@%s 処理が完了しました: 成功 %d 件、失敗 %d 件",
        $config->getTwitterOwnerScreenName(),
        $success_count,
        $failure_count
    );
    TwitterUtil::postTweet($connection, $param);
}
exit($failure_count > 0 ? 1 : 0);
