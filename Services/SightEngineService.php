<?php

namespace App\Services;

use Exception;
use App\Helper;
use App\Models\Media;
use App\Models\Updates;
use App\Models\Notifications;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Models\AdminSettings as Setting;

class SightEngineService
{
    private string $apiUser;
    private string|int $apiSecret;
    private string $baseUrl;

    public function __construct(
        protected SightEngineValidatorService $validation,
        protected SightEngineVideoValidatorService $videoValidation
    ) {
        $this->apiUser = Setting::value('sightengine_api_user');
        $this->apiSecret = Setting::value('sightengine_api_api_secret');
        $this->baseUrl = 'https://api.sightengine.com/1.0/';
    }

    public function checkImage(Media $media)
    {
        try {
            $pathFile = config('path.images') . $media->image;
            $imageUrl = Helper::getFile($pathFile);
            // Get post
            $getPost = Updates::with(['media'])->whereId($media->updates_id)->first();

            if (!$getPost) {
                return;
            }

            $date = $getPost->editing ? $getPost->date : now();

            // Status final post
            $statusPost = $getPost->schedule ? 'schedule' : 'active';
            $statusFinalPost = Setting::value('auto_approve_post') == 'on' ? $statusPost : 'pending';

            // Check if there are other media that have not been moderated
            $mediaPending = $getPost->media->where('status', 'pending')->count();

            $response = Http::get($this->baseUrl . '/check.json', [
                'url' => $imageUrl,
                'models' => 'nudity-2.1,violence',
                'api_user' => $this->apiUser,
                'api_secret' => $this->apiSecret,
            ]);

            if ($response->successful()) {
                $validation = $this->validation->validateContent($response->json());

                if ($validation['approved']) {
                    // Update status of image
                    $media->status = 'active';
                    $media->save();

                    // Update status of post
                    if ($mediaPending == 0 && $getPost->status == 'pending') {
                        $getPost->update([
                            'date' => $date,
                            'status' => $statusFinalPost
                        ]);

                        if ($statusFinalPost == 'active') {
                            Notifications::send($getPost->user_id, 1, 8, $getPost->id);
                        }
                    }
                } else {
                    // Send notification to user
                    Notifications::send($getPost->user_id, 1, 33, $getPost->user_id, $media->file_name);

                    $this->deleteMedia($media, $getPost, $pathFile, $statusFinalPost);
                }
            }
        } catch (Exception $e) {
            info('Error in SightEngineService checkImage():', [
                'message' => $e->getMessage(),
                'fileName' => $media->file_name,
            ]);

            Notifications::send($getPost->user_id, 1, 34, 0, $media->file_name);

            $this->deleteMedia($media, $getPost, $pathFile, $statusFinalPost);
        }
    }

    public function checkVideo(Media $media)
    {
        try {
            $pathFile = config('path.videos') . $media->video;
            $videoPath = Helper::getFile($pathFile);
            // Get post
            $getPost = Updates::with(['media'])->whereId($media->updates_id)->first();

            if (!$getPost) {
                return;
            }

            $date = $getPost->editing ? $getPost->date : now();

            // Status final post
            $statusPost = $getPost->schedule ? 'schedule' : 'active';
            $statusFinalPost = Setting::value('auto_approve_post') == 'on' ? $statusPost : 'pending';

            // Check if there are other media that have not been moderated
            $mediaPending = $getPost->media->where('status', 'pending')->count();

            $response = Http::attach(
                'media',
                file_get_contents($videoPath),
                basename($videoPath)
            )->post($this->baseUrl . '/video/check-sync.json', [
                'models' => 'nudity-2.1,violence',
                'api_user' => $this->apiUser,
                'api_secret' => $this->apiSecret,
            ]);

            if ($response->successful()) {
                $validation = $this->videoValidation->validateVideoContent($response->json());

                if ($validation['approved']) {
                    // Update status of video
                    $media->status = 'active';
                    $media->save();

                    // Update status of post
                    if ($mediaPending == 0 && $getPost->status == 'pending') {
                        $getPost->update([
                            'date' => $date,
                            'status' => $statusFinalPost
                        ]);

                        if ($statusFinalPost == 'active') {
                            Notifications::send($getPost->user_id, 1, 8, $getPost->id);
                        }
                    }
                } else {
                    // Send notification to user
                    Notifications::send($getPost->user_id, 1, 33, $getPost->user_id, $media->file_name);

                    $this->deleteMedia($media, $getPost, $pathFile, $statusFinalPost);
                }
            }
        } catch (Exception $e) {
            info('Error in SightEngineService checkVideo():', [
                'message' => $e->getMessage(),
                'fileName' => $media->file_name,
            ]);

            Notifications::send($getPost->user_id, 1, 34, 0, $media->file_name);

            $this->deleteMedia($media, $getPost, $pathFile, $statusFinalPost);
        }
    }

    protected function deleteMedia(Media $media, Updates $post, string $pathFile, $statusFinalPost)
    {
        Storage::delete($pathFile);

        $getMediaPending = $post->media->where('status', 'pending')->count();

        // Delete post
        if (!$post->editing && $getMediaPending == 0) {
            $post->update([
                'status' => $statusFinalPost
            ]);
        }

        // Delete video
        $media->delete();
    }
}
