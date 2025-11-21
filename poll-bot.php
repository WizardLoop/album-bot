<?php 

declare(strict_types=1);

/*
    Telegram Album Bot
    Created by @wizardloop
    polling version - based MadelineProto
*/

use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\Message\PrivateMessage;
use danog\MadelineProto\EventHandler\SimpleFilter\Incoming;
use danog\MadelineProto\SimpleEventHandler;
use danog\MadelineProto\EventHandler\Filter\FilterIncoming;
use Amp\File;

if (class_exists(API::class)) {
} elseif (file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
} else {
    if (!file_exists(__DIR__."/".'madeline.php')) {
        copy('https://phar.madelineproto.xyz/madeline.php', __DIR__."/".'madeline.php');
    }
    require_once __DIR__."/".'madeline.php';
}

class AlbumBot extends SimpleEventHandler
{

private array $albumTimers = [];

private function processAlbumPart(object $message)
{
    $senderId  = $message->senderId;
    $groupedId = $message->groupedId;

    if (!$groupedId) return;

    $dir = __DIR__ . "/data/$senderId";
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $albumFile = "$dir/album_$groupedId.json";
    $timerFile = "$dir/album_timer_$groupedId";

    $album = file_exists($albumFile)
        ? json_decode(\Amp\File\read($albumFile), true)
        : [];

    $media = $message->media ?? null;
    if (!$media) return;

    $botApiFileId = $media->botApiFileId ?? null;

    if ($media instanceof \danog\MadelineProto\EventHandler\Media\Photo) {
        $fileType = 'photo';
    } elseif ($media instanceof \danog\MadelineProto\EventHandler\Media\Document) {
        $fileType = 'document';
    } elseif ($media instanceof \danog\MadelineProto\EventHandler\Media\Video) {
        $fileType = 'video';
    } elseif ($media instanceof \danog\MadelineProto\EventHandler\Media\Animation) {
        $fileType = 'animation';
    } else {
        $fileType = null;
    }

    if (!$botApiFileId || !$fileType) return;

    $savedMedia = [
        'type'         => $fileType,
        'botApiFileId' => $botApiFileId
    ];

    $entitiesTL = $message->entities
        ? array_map(fn($e) => $e->toMTProto(), $message->entities)
        : [];

    $album[] = [
        'media'    => $savedMedia,
        'caption'  => $message->message ?? "",
        'entities' => $entitiesTL,
        'index'    => count($album),
        'msg_id'   => $message->id,
    ];

    \Amp\File\write($albumFile, json_encode($album, JSON_PRETTY_PRINT));
    \Amp\File\write($timerFile, (string) time());

    if (isset($this->albumTimers[$senderId][$groupedId])) {
        \Revolt\EventLoop::cancel($this->albumTimers[$senderId][$groupedId]);
    }

    $this->albumTimers[$senderId][$groupedId] =
        \Revolt\EventLoop::delay(1.0, function () use ($senderId, $groupedId, $albumFile, $timerFile) {

        if (!file_exists($timerFile)) return;

        if (time() - (int)\Amp\File\read($timerFile) < 1) return;

        $album = json_decode(\Amp\File\read($albumFile), true) ?? [];
        if (!$album) return;

        @unlink($albumFile);
        @unlink($timerFile);
        unset($this->albumTimers[$senderId][$groupedId]);

        usort($album, fn($a, $b) => $a['index'] <=> $b['index']);

        $chunks = array_chunk($album, 10);

        foreach ($chunks as $chunk) {
            $multiMedia = [];

            foreach ($chunk as $item) {
                $m = $item['media'];

                if ($m['type'] === 'photo') {
                    $mediaArray = [
                        '_'  => 'inputMediaPhoto',
                        'id' => $m['botApiFileId'],
                    ];
                } else {
                    $mediaArray = [
                        '_'  => 'inputMediaDocument',
                        'id' => $m['botApiFileId'],
                    ];
                }

                $multiMedia[] = [
                    '_'        => 'inputSingleMedia',
                    'media'    => $mediaArray,
                    'message'  => $item['caption'],
                    'entities' => $item['entities'] ?? []
                ];
            }

            try {
                $res = $this->messages->sendMultiMedia(peer: $senderId, multi_media: $multiMedia);

                try {
                    $msgIds = array_map(fn($x) => $x['msg_id'], $chunk);
                    $this->messages->deleteMessages(revoke: true, id: $msgIds);
                } catch (\Throwable $e) {}
                
            } catch (\Throwable $e) {}
        }
    });
}

// usage example:
#[Handler]
public function handler(Incoming & PrivateMessage $message): void {
		try {
        if($message->groupedId != null){
            $this->processAlbumPart($message);
            return;
		}else{
            $this->messages->sendMedia(
            peer: $message->senderId,
            media: $message->media,
            message: $message->message ?? "",
            entities: $message->entities ?? []
            );
            $this->messages->deleteMessages(revoke: true, id: [$message->id]);
        }
		} catch (Throwable $e) {}
	}

}

$API_ID = 'API_ID';
$API_HASH = 'API_HASH';
$BOT_TOKEN = 'BOT_TOKEN';
$settings = new \danog\MadelineProto\Settings;
$settings->setAppInfo((new \danog\MadelineProto\Settings\AppInfo)->setApiId((int)$API_ID)->setApiHash($API_HASH));
AlbumBot::startAndLoopBot(__DIR__.'/bot.madeline', $BOT_TOKEN, $settings);
