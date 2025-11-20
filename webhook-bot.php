<?php
/*
    Telegram Album Bot
    Created by @wizardloop
    webhook version
*/

ob_start();

define('API_KEY', 'YOUR_API_KEY_HERE');
$adminx = "CHAT_ID_HERE";

// --- Telegram Bot Request ---
function bot($method, $datas = []) {
    $url = "https://api.telegram.org/bot".API_KEY."/".$method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
    $res = curl_exec($ch);
    return json_decode($res);
}

// --- Get incoming update ---
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
}

if ($callback) {
    $chat_id = $callback['message']['chat']['id'];
    $from_id = $callback['from']['id'];
}

// --- Setup storage files ---
if (!is_dir('data')) mkdir('data', 0777, true);
$userMediaFile = "data/media$from_id.json";
$checkFile     = "data/var$from_id.txt";
$editMsgFile   = "data/msgfile$from_id.txt";

// --- Start album collection ---
if ($message && $text === "/album") {
    startAlbumCollection($chat_id, $checkFile, $editMsgFile);
    exit;
}

$check = file_exists($checkFile) ? trim(file_get_contents($checkFile)) : null;

// --- Collect media messages ---
if ($message && $check === "check") {
    handleIncomingMessage($message, $userMediaFile, $editMsgFile);
    bot('deleteMessage', ['chat_id'=>$chat_id, 'message_id'=>$message_id]);
    exit;
}

// --- Send collected media ---
if ($callback && $callback['data'] === "send_now") {
    sendCollectedMedia($userMediaFile, $editMsgFile, $checkFile, $chat_id, $adminx);
    exit;
}

// ===================== Functions =====================

function startAlbumCollection($chat_id, $checkFile, $editMsgFile) {
    file_put_contents($checkFile, "check");
    $msg_id = bot("sendMessage", [
        'chat_id' => $chat_id,
        'text' => "*Send messages or albums now*\nPhotos & Videos go to album, others sent individually",
        'parse_mode' => 'Markdown'
    ])->result->message_id;
    file_put_contents($editMsgFile, $msg_id);
}

function handleIncomingMessage($message, $userMediaFile, $editMsgFile) {
    $text = $message['text'] ?? '';
    $caption = $message['caption'] ?? '';
    
    $userMedia = file_exists($userMediaFile) ? json_decode(file_get_contents($userMediaFile), true) : [];
    $albumMedia = [];
    $singleMedia = [];
    $msgText = $text ?: $caption;

    // --- Check text length ---
    if ($msgText && mb_strlen($msgText) > 1024) {
        bot('deleteMessage', ['chat_id'=>$message['chat']['id'],'message_id'=>$message['message_id']]);
        $warn = bot('sendMessage',['chat_id'=>$message['chat']['id'],'text'=>"✖️ Message too long: ".mb_strlen($msgText),'parse_mode'=>'Markdown'])->result->message_id;
        sleep(3);
        bot('deleteMessage',['chat_id'=>$message['chat']['id'],'message_id'=>$warn]);
        return;
    }

    // --- Album: photo & video only ---
    if (isset($message['photo'])) $albumMedia[] = ['type'=>'photo','file_id'=>end($message['photo'])['file_id'],'caption'=>$caption];
    if (isset($message['video'])) $albumMedia[] = ['type'=>'video','file_id'=>$message['video']['file_id'],'caption'=>$caption];

    if (isset($message['animation'])) {
    $singleMedia[] = ['type'=>'animation','file_id'=>$message['animation']['file_id'],'caption'=>$caption];
    }

    if (isset($message['document'])) {
    $isGif = $message['document']['mime_type'] ?? '' === 'video/gif';
    $alreadyAdded = in_array($message['document']['file_id'], array_column($singleMedia, 'file_id'));
    if (!$isGif && !$alreadyAdded) {
        $singleMedia[] = ['type'=>'document','file_id'=>$message['document']['file_id'],'caption'=>$caption];
    }
    }

    if (isset($message['video_note'])) $singleMedia[] = ['type'=>'video_note','file_id'=>$message['video_note']['file_id'],'caption'=>$caption];
    if ($text && !preg_match('/^\/album/i', $text)) $singleMedia[] = ['type'=>'text','text'=>$text];

    // --- Merge and save ---
    $userMedia = array_merge($userMedia, $albumMedia, $singleMedia);
    file_put_contents($userMediaFile, json_encode($userMedia));

    // --- Counter ---
    $counts = ['photo'=>0,'video'=>0,'animation'=>0,'document'=>0,'video_note'=>0,'text'=>0];
    foreach ($userMedia as $m) { if(isset($counts[$m['type']])) $counts[$m['type']]++; }

    $counterText = "📨 *".count($userMedia)."* message(s) collected.\n";
    $counterText .= "📷 Photos: ".$counts['photo']."\n";
    $counterText .= "🎥 Videos: ".$counts['video']."\n";
    $counterText .= "🎞️ Animations: ".$counts['animation']."\n";
    $counterText .= "📄 Documents: ".$counts['document']."\n";
    $counterText .= "📹 Video Notes: ".$counts['video_note']."\n";
    $counterText .= "💬 Texts: ".$counts['text']."\n";
    $counterText .= "Press **Done** when ready.";

    $editMsgId = trim(file_get_contents($editMsgFile));
    bot('editMessageText', [
        'chat_id'=>$message['chat']['id'],
        'message_id'=>$editMsgId,
        'text'=>$counterText,
        'parse_mode'=>'Markdown',
        'reply_markup'=>json_encode(['inline_keyboard'=>[[['text'=>"✅ Done",'callback_data'=>"send_now"]]]])
    ]);
}

function sendCollectedMedia($userMediaFile, $editMsgFile, $checkFile, $chat_id, $adminx) {
    bot('answerCallbackQuery', ['callback_query_id'=>$_POST['id'] ?? '']);

    $userMedia = file_exists($userMediaFile) ? json_decode(file_get_contents($userMediaFile), true) : [];
    $albumBatch = [];
    $singleList = [];

    foreach ($userMedia as $m) {
        if(in_array($m['type'], ['photo','video'])) $albumBatch[] = ['type'=>$m['type'],'media'=>$m['file_id'],'caption'=>$m['caption'] ?? ''];
        else $singleList[] = $m;
    }

    // --- Send albums ---
    foreach(array_chunk($albumBatch,10) as $chunk) {
        bot('sendMediaGroup',['chat_id'=>$adminx,'media'=>json_encode($chunk),'disable_notification'=>true]);
    }

    // --- Send single items ---
    foreach($singleList as $item){
        switch($item['type']){
            case 'text': bot('sendMessage',['chat_id'=>$adminx,'text'=>$item['text'],'parse_mode'=>'HTML']); break;
            case 'animation': bot('sendAnimation',['chat_id'=>$adminx,'animation'=>$item['file_id'],'caption'=>$item['caption'] ?? '']); break;
            case 'document': bot('sendDocument',['chat_id'=>$adminx,'document'=>$item['file_id'],'caption'=>$item['caption'] ?? '']); break;
            case 'video_note': bot('sendVideoNote',['chat_id'=>$adminx,'video_note'=>$item['file_id']]); break;
        }
    }

    // --- Cleanup ---
    if(file_exists($userMediaFile)) unlink($userMediaFile);
    if(file_exists($checkFile)) unlink($checkFile);
    if(file_exists($editMsgFile)){
        $editMsgId = trim(file_get_contents($editMsgFile));
        bot('deleteMessage',['chat_id'=>$chat_id,'message_id'=>$editMsgId]);
        unlink($editMsgFile);
    }

    bot('sendMessage',['chat_id'=>$chat_id,'text'=>"<b>Messages sent successfully ☑️</b>",'parse_mode'=>'HTML']);
}
