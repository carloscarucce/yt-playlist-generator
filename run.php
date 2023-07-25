<?php

use Orhanerday\OpenAi\OpenAi;
use Ytfts\Downloader\Downloader as YoutubeDownloader;

require __DIR__.'/vendor/autoload.php';

if (!is_file('config.ini')) {
    die('File: config.ini not found');
}

$outputDirectory = null;

do {
    $videoTitle = readline('Video title: ');
    $outputDirectory = __DIR__.'/videos/'.$videoTitle;
    $invalidResponse = empty(trim($videoTitle)) || is_dir($outputDirectory);

    if ($invalidResponse) {
        echo "Invalid video title. Please try again...\r\n";
    }
} while($invalidResponse);

mkdir($outputDirectory);

$openAiSearchString = readline('Playlist for: ');
$configs = parse_ini_file('config.ini', true);

$openAi = new OpenAi($configs['OpenAI']['ApiKey']);
$openAiResponse = json_decode($openAi->chat([
    'model' => 'gpt-3.5-turbo',
    'messages' => [
        [
            "role" => "user",
            "content" => "List youtube links for \"$openAiSearchString\"",
        ],
    ]
]), true);

$songs = [];

if (isset($openAiResponse['choices'][0])) {
    $message = $openAiResponse['choices'][0]['message']['content'];

    preg_match_all('/https:\/\/www.youtu\S+/', $message, $matches, PREG_SET_ORDER);
    $songs = array_column($matches, 0);
}

if (empty($songs)) {
    echo "Could not find any songs...\r\n";
    die();
}

echo "Found the following videos: \r\n", implode("\r\n", $songs), "\r\n";
if (readline('Proceed (y/n)? ') != 'y') {
    rmrdir($outputDirectory);
    die();
}

$downloader = new YoutubeDownloader();

foreach ($songs as $i => $song) {
    $info = $downloader->getInf($song);

    if (empty($info['download']['audio'][0])) {
        echo "Error downloading song $song\r\n";
        continue;
    }

    $audios = $info['download']['audio'] ?? [];

    $bestAudio = null;

    foreach ($audios as $audio) {
        if (!in_array($audio['container'], ['mp3', 'mp4'])) {
            continue;
        }

        if (is_null($bestAudio) || $bestAudio['size']['simple'] < $audio['size']['simple']) {
            $bestAudio = $audio;
        }
    }

    if (
        is_null($bestAudio)
        && readline("Could not find a fitting audio for song [$song]. Cancel operation? (y/n)") == 'y'
    ) {
        rmrdir($outputDirectory);
        die();
    }

    if(!is_dir("$outputDirectory/audios/")) {
        mkdir("$outputDirectory/audios/");
    }

    echo "Downloading video: {$info['title']} (", ($i + 1), '/', count($songs),')', "\r\n";
    file_put_contents("$outputDirectory/audios/audio-$i.mp3", file_get_contents($bestAudio['url']));
}


if (!($bgOrigin = readline('Background image (blank for default): '))) {
    $bgOrigin = __DIR__.'/bg-default.jpg';
}
file_put_contents("$outputDirectory/background.jpg", file_get_contents($bgOrigin));

if (is_dir("$outputDirectory/audios")) {
    echo "\r\nGenerating output file.";
    $generatedAudios = array_filter(scandir("$outputDirectory/audios"), fn($item) => !in_array($item, ['.', '..']));

    if (!empty($generatedAudios)) {
        exec(
            "ffmpeg -i $outputDirectory/background.jpg -i \"concat:$outputDirectory/audios/"
            .implode("|$outputDirectory/audios/", $generatedAudios)
            ."\" $outputDirectory/video.mp4"
        );
    }
} else {
    echo "\r\nNo audios were downloaded.";
    rmrdir($outputDirectory);
}


echo "\r\n\r\nFinishing...\r\n";
