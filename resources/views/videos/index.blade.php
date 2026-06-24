<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Library - Subtitle System</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --primary: #38bdf8;
            --text-main: #f1f5f9;
            --text-dim: #94a3b8;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            padding: 40px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
        }

        h1 {
            font-size: 2.5rem;
            margin: 0;
            background: linear-gradient(to right, #38bdf8, #818cf8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .upload-btn {
            background: var(--primary);
            color: var(--bg-color);
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .upload-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(56, 189, 248, 0.3);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
        }

        .video-card {
            background: var(--card-bg);
            border-radius: 16px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .video-card:hover {
            transform: scale(1.02);
            border-color: var(--primary);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .thumbnail {
            width: 100%;
            height: 180px;
            background: #000;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .play-icon {
            width: 50px;
            height: 50px;
            background: rgba(56, 189, 248, 0.8);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: 0.3s;
        }

        .video-card:hover .play-icon {
            opacity: 1;
        }

        .card-info {
            padding: 20px;
        }

        .video-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .video-meta {
            font-size: 0.9rem;
            color: var(--text-dim);
        }

        .pagination {
            margin-top: 50px;
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .pagination a, .pagination span {
            padding: 8px 16px;
            background: var(--card-bg);
            color: var(--text-main);
            text-decoration: none;
            border-radius: 6px;
        }

        .pagination .active {
            background: var(--primary);
            color: var(--bg-color);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Video Library</h1>
            <a href="{{ route('upload.create') }}" class="upload-btn">+ Upload New</a>
        </div>

        <div class="grid">
            @forelse($videos as $video)
                <div class="video-card" onclick="window.location='{{ route('video.show', $video->id) }}'">
                    <div class="thumbnail">
                        <div class="play-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="white">
                                <path d="M8 5v14l11-7z"/>
                            </svg>
                        </div>
                    </div>
                    <div class="card-info">
                        <div class="video-title">{{ $video->filename ?? 'Untitled Video' }}</div>
                        <div class="video-meta">
                            Added {{ $video->created_at->diffForHumans() }} • 
                            @if($video->target_language !== 'en') EN, {{ strtoupper($video->target_language) }} @else EN only @endif
                        </div>
                    </div>
                </div>
            @empty
                <div style="grid-column: 1/-1; text-align: center; padding: 100px; color: var(--text-dim);">
                    No videos found. Start by uploading one!
                </div>
            @endforelse
        </div>

        <div class="pagination">
            {{ $videos->links() }}
        </div>
    </div>
</body>
</html>
