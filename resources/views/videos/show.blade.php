<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $video->filename }} - Subtitle Player</title>
    
    <!-- Video.js CSS -->
    <link href="https://vjs.zencdn.net/8.10.0/video-js.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-color: #0b0f19;
            --primary: #38bdf8;
            --text-main: #f1f5f9;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            padding: 40px;
        }

        .player-container {
            width: 100%;
            max-width: 1000px;
            background: #161e2e;
            padding: 10px;
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.5);
        }

        .video-js {
            width: 100%;
            border-radius: 12px;
            overflow: hidden;
        }

        .info-panel {
            width: 100%;
            max-width: 1000px;
            margin-top: 30px;
        }

        .back-btn {
            color: var(--primary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            margin-bottom: 20px;
            font-weight: 600;
        }

        h1 {
            margin: 0 0 10px 0;
            font-size: 1.8rem;
        }

        .meta {
            color: #94a3b8;
        }

        /* Custom Video.js Styling */
        .vjs-theme-city .vjs-big-play-button {
            background-color: var(--primary);
            border-radius: 50%;
            width: 80px;
            height: 80px;
            line-height: 80px;
        }
    </style>
</head>
<body>
    <div class="info-panel">
        <a href="{{ route('video.index') }}" class="back-btn">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 8px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Back to Library
        </a>
        <h1>{{ $video->filename }}</h1>
        <div class="meta">Status: Completed • Language: {{ strtoupper($video->target_language) }}</div>
    </div>

    <div class="player-container">
        <video
            id="my-video"
            class="video-js vjs-big-play-centered vjs-16-9"
            controls
            preload="auto"
            data-setup="{}"
        >
            <!-- Proxy Stream URL -->
            <source src="{{ route('video.stream', $video->id) }}" type="video/mp4" />
            
            <!-- Subtitle Tracks via Proxy -->
            <track 
                kind="subtitles" 
                src="{{ route('video.subtitle', [$video->id, 'en']) }}" 
                srclang="en" 
                label="English" 
                default
            >

            @if($video->target_language !== 'en')
                <track 
                    kind="subtitles" 
                    src="{{ route('video.subtitle', [$video->id, $video->target_language]) }}" 
                    srclang="{{ $video->target_language }}" 
                    label="{{ strtoupper($video->target_language) }}"
                >
            @endif

            <p class="vjs-no-js">
                To view this video please enable JavaScript, and consider upgrading to a
                web browser that
                <a href="https://videojs.com/html5-video-support/" target="_blank">supports HTML5 video</a>
            </p>
        </video>
    </div>

    <!-- Video.js Script -->
    <script src="https://vjs.zencdn.net/8.10.0/video.js"></script>
    <script>
        var player = videojs('my-video');
    </script>
</body>
</html>
