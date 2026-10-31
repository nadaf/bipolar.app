<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Care\CareGrantService;
use App\Domain\Care\GrantDirectory;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CareInviteController extends Controller
{
    public function accept(Request $request, GrantDirectory $dir, string $token)
    {
        abort_unless(strlen($token) >= 32 && strlen($token) <= 100, 404);
        $g = $dir->accept($request->user(), $token);
        abort_if($g === null, 404);

        return ['data' => CareGrantService::toApi($g)];
    }
}
