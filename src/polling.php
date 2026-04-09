<?php
declare(strict_types=1);

/*
    Telegram Album Bot
    Created by @wizardloop
    polling version - based MadelineProto
    https://github.com/danog/MadelineProto/
*/

class Albums extends \danog\MadelineProto\SimpleEventHandler
{

private array $albums = [];
private array $albumTimers = [];

private function saveAlbum(\danog\MadelineProto\EventHandler\Message $message, int $toSend, ?bool $del = false): void
{
    $senderId  = $message->senderId;
    $groupedId = $message->groupedId;

    if (!$groupedId) return;

    $media = $message->media ?? null;
    if (!$media) return;

    $botApiFileId = $media->botApiFileId ?? null;
    if (!$botApiFileId) return;

    $fileType = match (true) {
        $media instanceof \danog\MadelineProto\EventHandler\Media\Photo => 'photo',
        $media instanceof \danog\MadelineProto\EventHandler\Media\Document => 'document',
        $media instanceof \danog\MadelineProto\EventHandler\Media\Video => 'video',
        $media instanceof \danog\MadelineProto\EventHandler\Media\Animation => 'animation',
        default => null
    };
    if (!$fileType) return;

    $entities = $message->entities
        ? array_map(fn($e) => $e->toMTProto(), $message->entities)
        : [];

    if (!isset($this->albums[$senderId][$groupedId])) {
        $this->albums[$senderId][$groupedId] = [];
    }

    $this->albums[$senderId][$groupedId][] = [
        'media' => [
            'type' => $fileType,
            'botApiFileId' => $botApiFileId
        ],
        'caption'  => $message->message ?? "",
        'entities' => $entities,
        'index' => count($this->albums[$senderId][$groupedId] ?? []),
        'msg_id' => $message->id
    ];

    if ($del) {
    try { $this->messages->deleteMessages(revoke: true, id: [$message->id]); } catch (\Throwable) {}
    }

    if (isset($this->albumTimers[$senderId][$groupedId])) {
        \Revolt\EventLoop::cancel($this->albumTimers[$senderId][$groupedId]);
    }

    $this->albumTimers[$senderId][$groupedId] =
        \Revolt\EventLoop::delay(1.0, function () use ($senderId, $toSend, $groupedId) {

            if (!isset($this->albums[$senderId][$groupedId])) return;

            $this->sendAlbum($senderId, $toSend, $groupedId);

            unset($this->albums[$senderId][$groupedId]);
            unset($this->albumTimers[$senderId][$groupedId]);
        });
}

private function sendAlbum(int $senderId, int $toSend, int $groupedId): void 
{
    $album = $this->albums[$senderId][$groupedId] ?? [];
    if (!$album) return;

    usort($album, fn($a, $b) => $a['index'] <=> $b['index']);

    $chunks = array_chunk($album, 10);

    foreach ($chunks as $chunk) {
        $multiMedia = [];
        foreach ($chunk as $item) {
            $m = $item['media'];
            $mediaArray = $m['type'] === 'photo'
                ? ['_' => 'inputMediaPhoto', 'id' => $m['botApiFileId']]
                : ['_' => 'inputMediaDocument', 'id' => $m['botApiFileId']];
            $multiMedia[] = [
                '_'        => 'inputSingleMedia',
                'media'    => $mediaArray,
                'message'  => $item['caption'],
                'entities' => $item['entities'] ?? []
            ];
        }
        try {
            $this->messages->sendMultiMedia(
                peer: $toSend,
                multi_media: $multiMedia
            );
        } catch (\Throwable $e) {}
    }
}

# Usage Example:
$senderId  = $message->senderId;
$groupedId = $message->groupedId ?? null;
if ($groupedId) {
    $this->saveAlbum($message, $senderId, false);
    return;
}

}

$settings = new \danog\MadelineProto\Settings;
$settings->yourSettingsHere;
Albums::startAndLoopBot('bot.madeline', 'bot token', $settings);
