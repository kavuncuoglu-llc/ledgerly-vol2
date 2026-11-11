<?php

namespace App\Ai\Training;

// Chapter 40, Topic 8: the Hugging Face Hub is a home for datasets that contain no customer data. Even a private repository puts the files on Hugging Face's servers, so only the synthetic, replay and exemplar files ever reach this interface.
interface HubUploader
{
    /**
     * @param  array<string, string>  $files  path in the repository => local file
     * @return string the commit the files were uploaded as
     */
    public function upload(string $repository, array $files): string;
}
