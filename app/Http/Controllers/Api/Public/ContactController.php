<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\ContactRequest;
use App\Models\ContactMessage;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class ContactController extends Controller
{
    use ApiResponse;

    public function store(ContactRequest $request): JsonResponse
    {
        ContactMessage::create($request->validated());

        return $this->successResponse(null, 'Your message has been received. We will get back to you shortly.', 201);
    }
}
