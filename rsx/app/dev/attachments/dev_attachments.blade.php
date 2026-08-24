@rsx_id('Dev_Attachments')
@rsx_extends('Dev_Layout')

@section('title', 'Attachments')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-0">File Attachments</h1>
            <p class="text-muted">Testing and demonstration of file upload and thumbnail features</p>
        </div>
    </div>

    {{-- Basic Image Upload Card --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Basic Image Upload</h5>
                </div>
                <div class="card-body">
                    {{-- Upload Section --}}
                    <div class="mb-4">
                        <label class="form-label fw-bold">Upload File</label>
                        <div class="d-flex align-items-center gap-3">
                            <input type="file" id="basic-upload-input" class="d-none">
                            <button type="button" id="basic-upload-btn" class="btn btn-primary">
                                <i class="bi bi-upload"></i> Choose File
                            </button>
                            <span id="upload-status" class="text-muted">No file selected</span>
                        </div>
                        <div class="form-text">Any file type accepted. Max size {{ \App\RSpade\Core\Ajax\Ajax::max_file_size_human() }}.</div>
                    </div>

                    <hr>

                    {{-- Display Section - Hidden until upload --}}
                    <div id="thumbnails-container" class="d-none">
                        <h6 class="mb-3">Generated Thumbnails</h6>

                        <div class="row g-4">
                            {{-- 96x96 Profile Image Cover --}}
                            <div class="col-md-6 col-lg-4">
                                <div class="text-center">
                                    <div class="mb-2 fw-semibold">96x96 Profile Image Cover</div>
                                    <div class="position-relative d-inline-block">
                                        <div id="thumb-profile" class="rounded-circle border overflow-hidden" style="width: 96px; height: 96px;"></div>
                                        <div id="spinner-profile" class="position-absolute top-50 start-50 translate-middle d-none">
                                            <div class="spinner-border spinner-border-sm text-primary" role="status">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- 200x200 Thumbnail Cover --}}
                            <div class="col-md-6 col-lg-4">
                                <div class="text-center">
                                    <div class="mb-2 fw-semibold">200x200 Thumbnail Cover</div>
                                    <div id="thumb-200" class="border d-inline-block" style="width: 200px; height: 200px;"></div>
                                </div>
                            </div>

                            {{-- 240x180 Thumbnail Cover (stretch) --}}
                            <div class="col-md-6 col-lg-4">
                                <div class="text-center">
                                    <div class="mb-2 fw-semibold">240x180 Thumbnail Cover</div>
                                    <div id="thumb-240x180" class="border d-inline-block" style="width: 240px; height: 180px;"></div>
                                </div>
                            </div>

                            {{-- 100x100 Icon --}}
                            <div class="col-md-6 col-lg-4">
                                <div class="text-center">
                                    <div class="mb-2 fw-semibold">100x100 Icon</div>
                                    <img id="thumb-icon" src="" class="border" width="100" height="100" alt="Icon">
                                </div>
                            </div>

                            {{-- Action Buttons --}}
                            <div class="col-12">
                                <hr>
                                <div class="d-flex gap-2">
                                    <a id="btn-view-inline" href="#" target="_blank" class="btn btn-primary">
                                        <i class="bi bi-eye"></i> View Inline
                                    </a>
                                    <a id="btn-view-download" href="#" target="_blank" class="btn btn-secondary">
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- File Type Icons Card --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">File Type Icons</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">All icons rendered at 64x64 using the icon service endpoint</p>

                    {{-- Images --}}
                    <h6 class="mb-3">Images</h6>
                    <div class="d-flex flex-wrap gap-3 mb-4">
                        <div class="text-center">
                            <img src="/_icon_by_extension/jpg?width=256&height=256" style="width: 64px; height: 64px;" alt="JPG">
                            <div class="small text-muted mt-1">jpg</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/png?width=256&height=256" style="width: 64px; height: 64px;" alt="PNG">
                            <div class="small text-muted mt-1">png</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/gif?width=256&height=256" style="width: 64px; height: 64px;" alt="GIF">
                            <div class="small text-muted mt-1">gif</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/svg?width=256&height=256" style="width: 64px; height: 64px;" alt="SVG">
                            <div class="small text-muted mt-1">svg</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/psd?width=256&height=256" style="width: 64px; height: 64px;" alt="PSD">
                            <div class="small text-muted mt-1">psd</div>
                        </div>
                    </div>

                    {{-- Videos --}}
                    <h6 class="mb-3">Videos</h6>
                    <div class="d-flex flex-wrap gap-3 mb-4">
                        <div class="text-center">
                            <img src="/_icon_by_extension/mp4?width=256&height=256" style="width: 64px; height: 64px;" alt="MP4">
                            <div class="small text-muted mt-1">mp4</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/avi?width=256&height=256" style="width: 64px; height: 64px;" alt="AVI">
                            <div class="small text-muted mt-1">avi</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/mov?width=256&height=256" style="width: 64px; height: 64px;" alt="MOV">
                            <div class="small text-muted mt-1">mov</div>
                        </div>
                    </div>

                    {{-- Audio --}}
                    <h6 class="mb-3">Audio</h6>
                    <div class="d-flex flex-wrap gap-3 mb-4">
                        <div class="text-center">
                            <img src="/_icon_by_extension/mp3?width=256&height=256" style="width: 64px; height: 64px;" alt="MP3">
                            <div class="small text-muted mt-1">mp3</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/wav?width=256&height=256" style="width: 64px; height: 64px;" alt="WAV">
                            <div class="small text-muted mt-1">wav</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/flac?width=256&height=256" style="width: 64px; height: 64px;" alt="FLAC">
                            <div class="small text-muted mt-1">flac</div>
                        </div>
                    </div>

                    {{-- Documents --}}
                    <h6 class="mb-3">Documents</h6>
                    <div class="d-flex flex-wrap gap-3 mb-4">
                        <div class="text-center">
                            <img src="/_icon_by_extension/pdf?width=256&height=256" style="width: 64px; height: 64px;" alt="PDF">
                            <div class="small text-muted mt-1">pdf</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/doc?width=256&height=256" style="width: 64px; height: 64px;" alt="DOC">
                            <div class="small text-muted mt-1">doc</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/xls?width=256&height=256" style="width: 64px; height: 64px;" alt="XLS">
                            <div class="small text-muted mt-1">xls</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/ppt?width=256&height=256" style="width: 64px; height: 64px;" alt="PPT">
                            <div class="small text-muted mt-1">ppt</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/txt?width=256&height=256" style="width: 64px; height: 64px;" alt="TXT">
                            <div class="small text-muted mt-1">txt</div>
                        </div>
                    </div>

                    {{-- Archives --}}
                    <h6 class="mb-3">Archives</h6>
                    <div class="d-flex flex-wrap gap-3 mb-4">
                        <div class="text-center">
                            <img src="/_icon_by_extension/zip?width=256&height=256" style="width: 64px; height: 64px;" alt="ZIP">
                            <div class="small text-muted mt-1">zip</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/rar?width=256&height=256" style="width: 64px; height: 64px;" alt="RAR">
                            <div class="small text-muted mt-1">rar</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/7z?width=256&height=256" style="width: 64px; height: 64px;" alt="7Z">
                            <div class="small text-muted mt-1">7z</div>
                        </div>
                    </div>

                    {{-- Code --}}
                    <h6 class="mb-3">Code</h6>
                    <div class="d-flex flex-wrap gap-3 mb-4">
                        <div class="text-center">
                            <img src="/_icon_by_extension/php?width=256&height=256" style="width: 64px; height: 64px;" alt="PHP">
                            <div class="small text-muted mt-1">php</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/js?width=256&height=256" style="width: 64px; height: 64px;" alt="JS">
                            <div class="small text-muted mt-1">js</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/py?width=256&height=256" style="width: 64px; height: 64px;" alt="PY">
                            <div class="small text-muted mt-1">py</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/html?width=256&height=256" style="width: 64px; height: 64px;" alt="HTML">
                            <div class="small text-muted mt-1">html</div>
                        </div>
                    </div>

                    {{-- 3D Models & Other --}}
                    <h6 class="mb-3">3D Models & Other</h6>
                    <div class="d-flex flex-wrap gap-3 mb-4">
                        <div class="text-center">
                            <img src="/_icon_by_extension/stl?width=256&height=256" style="width: 64px; height: 64px;" alt="STL">
                            <div class="small text-muted mt-1">stl</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/ai?width=256&height=256" style="width: 64px; height: 64px;" alt="AI">
                            <div class="small text-muted mt-1">ai</div>
                        </div>
                        <div class="text-center">
                            <img src="/_icon_by_extension/unknown?width=256&height=256" style="width: 64px; height: 64px;" alt="Unknown">
                            <div class="small text-muted mt-1">unknown</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Future Features Card --}}
    <div class="row">
        <div class="col-12">
            <div class="card bg-light">
                <div class="card-body">
                    <h6 class="card-title">
                        <i class="bi bi-clock-history"></i> Planned Features
                    </h6>
                    <p class="mb-2">Additional uploader types to be implemented:</p>
                    <ul class="mb-2">
                        <li>Drag and drop file upload</li>
                        <li>Multiple file upload with queue</li>
                        <li>Upload with progress bar</li>
                        <li>Direct camera capture</li>
                        <li>URL-based file import</li>
                    </ul>
                    <p class="mb-2">Image viewer components to be tested:</p>
                    <ul class="mb-0">
                        <li>Gallery view with lightbox</li>
                        <li>Carousel/slider view</li>
                        <li>Grid view with lazy loading</li>
                        <li>Thumbnail strip navigation</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection