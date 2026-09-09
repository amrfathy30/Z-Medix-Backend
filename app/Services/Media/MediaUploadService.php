<?php

namespace App\Services\Media;

use App\Support\Media\PublicMediaUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Generic, reusable wrapper around Spatie Media Library so models only need
 * to implement HasMedia + register their collections — upload, replace,
 * delete, and URL-payload logic lives here instead of being duplicated
 * across controllers/Filament resources.
 */
class MediaUploadService
{
    public function upload(HasMedia $model, UploadedFile|string $file, string $collection, array $customProperties = []): Media
    {
        return $model
            ->addMedia($file)
            ->withCustomProperties($customProperties)
            ->toMediaCollection($collection);
    }

    /**
     * @param  array<int, UploadedFile|string>  $files
     * @return Collection<int, Media>
     */
    public function uploadMultiple(HasMedia $model, array $files, string $collection, array $customProperties = []): Collection
    {
        return collect($files)->map(
            fn (UploadedFile|string $file): Media => $this->upload($model, $file, $collection, $customProperties)
        );
    }

    /**
     * Replace whatever is in the collection with a single new file.
     * For collections registered with ->singleFile(), Spatie already
     * removes the previous file automatically; this also covers
     * multi-file collections where a full replace is desired.
     */
    public function replace(HasMedia $model, UploadedFile|string $file, string $collection, array $customProperties = []): Media
    {
        $media = $this->upload($model, $file, $collection, $customProperties);

        /** @var HasMedia $fresh */
        $fresh = $model->fresh();

        $fresh->getMedia($collection)
            ->reject(fn (Media $previous): bool => $previous->getKey() === $media->getKey())
            ->each(fn (Media $previous) => $this->delete($previous));

        if ($model instanceof Model && $model->relationLoaded('media')) {
            $model->unsetRelation('media');
        }

        return $media;
    }

    public function clearCollection(HasMedia $model, string $collection): void
    {
        $model->clearMediaCollection($collection);
    }

    public function delete(Media $media): bool
    {
        return $media->delete();
    }

    public function deleteFromCollection(HasMedia $model, string $collection, int $mediaId): bool
    {
        $media = $model->getMedia($collection)->firstWhere('id', $mediaId);

        return $media ? $this->delete($media) : false;
    }

    public function getUrl(HasMedia $model, string $collection, ?string $conversion = null): ?string
    {
        $media = $model->getFirstMedia($collection);

        return $media instanceof Media ? $this->publicUrl($media, $conversion) : null;
    }

    /** @return array<int, string> */
    public function getUrls(HasMedia $model, string $collection, ?string $conversion = null): array
    {
        return $model->getMedia($collection)
            ->map(fn (Media $media): string => $this->publicUrl($media, $conversion))
            ->values()
            ->all();
    }

    /** @return array{id: int, name: string, file_name: string, mime_type: ?string, size: int, url: string, collection: string} */
    public function toPayload(Media $media, ?string $conversion = null): array
    {
        return [
            'id' => $media->id,
            'name' => $media->name,
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'url' => $this->publicUrl($media, $conversion),
            'collection' => $media->collection_name,
        ];
    }

    private function publicUrl(Media $media, ?string $conversion = null): string
    {
        return (string) PublicMediaUrl::make(
            $conversion ? $media->getFullUrl($conversion) : $media->getFullUrl(),
        );
    }

    /** @return array<int, array{id: int, name: string, file_name: string, mime_type: ?string, size: int, url: string, collection: string}> */
    public function collectionToPayload(HasMedia $model, string $collection, ?string $conversion = null): array
    {
        return $model->getMedia($collection)
            ->map(fn (Media $media): array => $this->toPayload($media, $conversion))
            ->all();
    }
}
