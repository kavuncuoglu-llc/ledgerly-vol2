<?php

// Chapter 33, Topic 7: the image is part of the policy.
it('builds from a pinned interpreter image, installs nothing and runs as nobody', function () {
    $dockerfile = file_get_contents(base_path('ops/sandbox/Dockerfile'));

    expect($dockerfile)->toMatch('/^FROM python:3\.12\.\d+-slim-bookworm$/m')
        ->and($dockerfile)->toContain('USER 65534:65534')
        ->and($dockerfile)->not->toMatch('/pip install|apt-get install|curl|wget/');
});
