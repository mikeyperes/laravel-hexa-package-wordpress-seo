@extends('layouts.app')
@section('title', 'WordPress SEO Settings')
@section('header', 'WordPress SEO Settings')

@section('content')
<div class="max-w-4xl space-y-6"
     x-data="{
         url: @js($featuredImagesParentUrl),
         busy: '',
         message: '',
         success: false,
         async post(target, body) {
             const response = await fetch(target, {
                 method: 'POST',
                 headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                 body: JSON.stringify(body),
             });
             const data = await response.json().catch(() => ({}));
             this.success = response.ok && !!data.success;
             this.message = data.message || data.error || (data.errors ? Object.values(data.errors).flat().join(' ') : 'The request failed.');
             return data;
         },
         async save() {
             this.busy = 'save';
             try { const data = await this.post(@js(route('wordpress-seo.settings.save')), { featured_images_parent_url: this.url }); if (data.success) { this.url = data.featured_images_parent_url; } } finally { this.busy = ''; }
         },
         async testFolder() {
             this.busy = 'folder';
             try { await this.post(@js(route('wordpress-seo.settings.test-folder')), { url: this.url }); } finally { this.busy = ''; }
         },
     }">

    <p class="text-gray-500 text-sm">Settings the WordPress SEO skills read when they work on a site.</p>

    <div class="flex flex-wrap gap-2 text-xs">
        <span class="rounded-full px-3 py-1 font-semibold {{ $driveCanWrite ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">Google Drive write: {{ $driveCanWrite ? 'ready' : ($driveInstalled ? 'not configured' : 'package missing') }}</span>
        <span class="rounded-full px-3 py-1 font-semibold {{ $featuredImagesParentUrl !== '' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">Featured images folder: {{ $featuredImagesParentUrl !== '' ? 'set' : 'missing' }}</span>
        @if(Route::has('google-drive.settings'))<a class="font-medium text-blue-600 underline" href="{{ route('google-drive.settings') }}">Google Drive settings</a>@endif
    </div>

    <template x-if="message">
        <div class="p-4 rounded-lg text-sm border" :class="success ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200'" x-text="message"></div>
    </template>

    <section class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 sm:p-6 space-y-4">
        <div>
            <h2 class="text-base font-semibold text-gray-900">Featured images</h2>
            <p class="mt-1 text-xs text-gray-500">Each site gets a folder named after its domain inside this folder (for example <code>michaelperes.com</code>). Approved featured images are saved there, so the site folder is always found by its name.</p>
        </div>
        <label class="block text-sm">
            <span class="font-medium text-gray-700">Featured images folder</span>
            <div class="mt-1 flex flex-col gap-2 sm:flex-row">
                <input type="url" x-model="url" placeholder="https://drive.google.com/drive/folders/…" class="min-h-10 flex-1 rounded-lg border border-gray-300 px-3 text-sm">
                <button type="button" @click="testFolder()" :disabled="busy !== ''" class="min-h-10 rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-medium text-blue-700 hover:bg-blue-100 disabled:opacity-60" x-text="busy === 'folder' ? 'Testing…' : 'Test Folder'"></button>
            </div>
            <a x-show="url" :href="url" target="_blank" rel="noopener noreferrer" class="mt-1 inline-block text-xs text-blue-600 hover:underline">Open folder ↗</a>
        </label>
        <button type="button" @click="save()" :disabled="busy !== ''" class="min-h-10 rounded-lg bg-blue-600 px-4 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" x-text="busy === 'save' ? 'Saving…' : 'Save'"></button>
    </section>
</div>
@endsection
