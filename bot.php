<?php
/*
    webhook-bot-album
    Created by @wizardloop
*/

ob_start();

define('API_KEY', 'YOUR_BOT_TOKEN_HERE');
$adminx = "CHAT_ID";

function bot($method, $datas = []) {
    $url = "https://api.telegram.org/bot".API_KEY."/".$method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
    $res = curl_exec($ch);
    return json_decode($res);
}

$update = json_decode(file_get_contents('php://input'), true);

$message  = $update['message'] ?? null;
$callback = $update['callback_query'] ?? null;

if (!$message && !$callback) exit;

if ($message) {
    $chat_id = $message['chat']['id'];
    $message_id = $message['message_id'];
    $from_id = $message['from']['id'];
    $text = $message['text'] ?? '';
    $caption = $message['caption'] ?? '';
    $media_group_id = $message['media_group_id'] ?? null;
}

if ($callback) {
    $chat_id = $callback['message']['chat']['id'];
    $from_id = $callback['from']['id'];
}

if (!is_dir('data')) mkdir('data', 0777, true);

$userMediaFile = "data/media$from_id.json";
$checkFile     = "data/var$from_id.txt";
$editMsgFile   = "data/msgfile$from_id.txt";

if ($message && $text === "/album") {
    file_put_contents($checkFile, "check");
    $msg_id = bot("sendMessage", [
        'chat_id' => $chat_id,
        'text' => "*Send messages or albums now*\nSupported: Text, Photo, Video, Animation, Document",
        'parse_mode' => 'Markdown'
    ])->result->message_id;
    file_put_contents($editMsgFile, $msg_id);
    exit;
}

$check = file_exists($checkFile) ? trim(file_get_contents($checkFile)) : null;

if ($message && $check === "check") {

    $is_album = ($media_group_id !== null);

    $userMedia = file_exists($userMediaFile)
        ? json_decode(file_get_contents($userMediaFile), true)
        : [];

    $currentMedia = [];
    $msgText = $text ?: $caption;

    if ($msgText && mb_strlen($msgText) > 1024) {
        bot('deleteMessage', ['chat_id'=>$chat_id, 'message_id'=>$message_id]);
        $warn = bot('sendMessage', [
            'chat_id'=>$chat_id,
            'text'=>"✖️ Message too long: " . mb_strlen($msgText),
            'parse_mode'=>'Markdown'
        ])->result->message_id;
        sleep(3);
        bot('deleteMessage', ['chat_id'=>$chat_id, 'message_id'=>$warn]);
        exit;
    }

    if (isset($message['photo']))
        $currentMedia[] = ['type'=>'photo', 'file_id'=>end($message['photo'])['file_id'], 'caption'=>$caption];
    if (isset($message['video']))
        $currentMedia[] = ['type'=>'video', 'file_id'=>$message['video']['file_id'], 'caption'=>$caption];
    if (isset($message['animation']))
        $currentMedia[] = ['type'=>'animation', 'file_id'=>$message['animation']['file_id'], 'caption'=>$caption];
    if (isset($message['document']))
        $currentMedia[] = ['type'=>'document', 'file_id'=>$message['document']['file_id'], 'caption'=>$caption];
    if (isset($message['video_note']))
        $currentMedia[] = ['type'=>'video_note', 'file_id'=>$message['video_note']['file_id'], 'caption'=>$caption];

    if ($text && !preg_match('/^\/album/i', $text))
        $currentMedia[] = ['type'=>'text', 'text'=>$text];

    $userMedia = array_merge($userMedia, $currentMedia);
    file_put_contents($userMediaFile, json_encode($userMedia));

    $editMsgId = trim(file_get_contents($editMsgFile));
    $count = count($userMedia);

    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $editMsgId,
        'text' => "📨 *$count* message(s) collected.\nPress **Done** when ready.",
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text'=>"✅ Done", 'callback_data'=>"send_now"]]
            ]
        ])
    ]);

        bot('deleteMessage', ['chat_id'=>$chat_id, 'message_id'=>$message_id]);

    exit;
}

if ($callback && $callback['data'] === "send_now") {

    bot('answerCallbackQuery', ['callback_query_id'=>$callback['id']]);

    $userMedia = file_exists($userMediaFile)
        ? json_decode(file_get_contents($userMediaFile), true)
        : [];

    $media_batch = [];
    $text_list = [];

    foreach ($userMedia as $m) {
        if ($m['type'] === 'text')
            $text_list[] = $m['text'];
        else
            $media_batch[] = [
                'type' => $m['type'],
                'media' => $m['file_id'],
                'caption' => $m['caption'] ?? ''
            ];
    }

    foreach (array_chunk($media_batch, 10) as $chunk) {
        bot('sendMediaGroup', [
            'chat_id' => $adminx,
            'media' => json_encode($chunk),
            'disable_notification' => true
        ]);
    }

    foreach ($text_list as $t) {
        bot('sendMessage', [
            'chat_id' => $adminx,
            'text' => $t,
            'parse_mode' => 'HTML'
        ]);
    }

    if (file_exists($userMediaFile)) unlink($userMediaFile);
    if (file_exists($checkFile)) unlink($checkFile);

    if (file_exists($editMsgFile)) {
        $editMsgId = trim(file_get_contents($editMsgFile));
        bot('deleteMessage', ['chat_id'=>$chat_id, 'message_id'=>$editMsgId]);
        unlink($editMsgFile);
    }

    bot('sendMessage', [
        'chat_id'=>$chat_id,
        'text'=>"<b>Messages sent successfully ☑️</b>",
        'parse_mode'=>'HTML'
    ]);

    exit;
}
