@if(session('status'))<p role="status" class="notice">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert" class="errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
