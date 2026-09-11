<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfilePhotoRequest extends FormRequest
{
    /**
     * An allowlist of real types, not a denylist of dangerous ones. `mimetypes`
     * reads the file's own bytes rather than the name or the browser's claim,
     * and listing what is permitted means a format nobody thought about is
     * refused by default instead of admitted by default.
     *
     * SVG is absent, and that is the point: it is a scripting format wearing a
     * picture's name. `App\Support\Avatar` refuses it a second time by being
     * unable to decode it at all, which is the guarantee that survives someone
     * editing this list.
     *
     * The 2MB ceiling is not a preference — it is `upload_max_filesize` on this
     * host. A larger number here would be a promise PHP breaks first, and it
     * breaks it by handing the request an empty `$_FILES` and no explanation.
     *
     * The browser compresses to 1MB before anything is sent
     * (`resources/js/lib/image.ts`), so nobody reaches this in practice. The
     * gap between the two is deliberate headroom: a browser that can only
     * produce PNG may land above its own ceiling, and it should still arrive
     * rather than be refused at a boundary the person cannot see. A test keeps
     * the client's number at or below this one.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif',
                'max:2048',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'photo.mimetypes' => 'Choose a JPEG, PNG, WebP or GIF image.',
            'photo.max' => 'That image is too big. Choose one under 2 MB.',
        ];
    }
}
