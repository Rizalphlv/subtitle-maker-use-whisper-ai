<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VideoController extends Controller
{
    /**
     * Display a listing of completed videos.
     */
    public function index()
    {
        $videos = Video::where('status', 'done')
            ->orderBy('created_at', 'desc')
            ->paginate(9);

        return view('videos.index', compact('videos'));
    }

    /**
     * Show the video player.
     */
    public function show(Video $video)
    {
        return view('videos.show', compact('video'));
    }

    /**
     * Stream video file from MinIO.
     * This bypasses CORS issues.
     */
    public function stream(Video $video)
    {
        $path = $video->minio_path;

        if (!Storage::disk('minio')->exists($path)) {
            abort(404);
        }

        // Use Laravel's native response for S3 which handles Byte-Range (seeking)
        return Storage::disk('minio')->response($path);
    }

    /**
     * Serve VTT subtitle file from MinIO.
     * This bypasses CORS issues.
     */
    public function subtitle(Video $video, $language)
    {
        // Path matches the generation logic in VttGeneratorService
        $filename = $language === 'en' ? 'en.vtt' : 'id_translated.vtt';
        $path = "videos/{$video->upload_id}/subtitles/{$filename}";

        if (!Storage::disk('minio')->exists($path)) {
            abort(404);
        }

        $content = Storage::disk('minio')->get($path);

        return response($content)
            ->header('Content-Type', 'text/vtt')
            ->header('Access-Control-Allow-Origin', '*'); // Extra safety
    }
}
