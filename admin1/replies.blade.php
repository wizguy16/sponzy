@extends('admin.layout')

@section('content')
	<h5 class="mb-4 fw-light">
    <a class="text-reset" href="{{ url('panel/admin') }}">{{ __('admin.dashboard') }}</a>
      <i class="bi-chevron-right me-1 fs-6"></i>
      <span class="text-muted">{{ __('general.replies') }} ({{$data->total()}})</span>
  </h5>

<div class="content">
	<div class="row">
		<div class="col-lg-12">
			@if (session('success'))
      <div class="alert alert-success alert-dismissible fade show" role="alert">
              <i class="bi bi-check2 me-1"></i>	{{ session('success') }}

                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">
                  <i class="bi bi-x-lg"></i>
                </button>
                </div>
              @endif

			<div class="card shadow-custom border-0">
				<div class="card-body p-lg-4">

					<div class="d-lg-flex justify-content-lg-between align-items-center mb-2 w-100">
						<!-- form -->
						<form class="mt-lg-0 mt-2 position-relative" role="search" autocomplete="off"
							action="{{ url('panel/admin/replies') }}" method="get">
							<i class="bi bi-search btn-search bar-search"></i>
							<input type="text" name="q" class="form-control ps-5 w-auto" value="" placeholder="{{ __('general.search') }}">
						</form><!-- form -->
					</div>

					<div class="table-responsive p-0">
						<table class="table table-hover">
						 <tbody>

               @if ($data->count() !=  0)
                  <tr>
                     <th class="active">{{ __('admin.user') }}</th>
                     <th class="active">{{ __('general.post') }}</th>
					 <th class="active">{{ __('general.comment') }}</th>
                     <th class="active">{{ __('admin.date') }}</th>
                     <th class="active">{{ __('admin.actions') }}</th>
                   </tr>

                 @foreach ($data as $comment)
					<tr>
					<td>
						@if (!isset($comment->user->username))
						{{ __('general.no_available') }}
										@else
                        <a href="{{ url($comment->user->username) }}" target="_blank">
                            {{ $comment->user->name }} <i class="fa fa-external-link-square-alt"></i>
                        </a>
						@endif
                        </td>
                        <td>
							@if (!isset($comment->posts->creator->username))
							{{ __('general.no_available') }}
										@else
                            <a href="{{ url($comment->posts->creator->username, ['post', $comment->posts->id]) }}" target="_blank">
                                {{__('general.go_to_post')}}  <i class="fa fa-external-link-square-alt"></i>
                            </a>
							@endif
                        </td>
					<td>
						{{ $comment->reply }}
					</td>
						<td>{{ Helper::formatDate($comment->created_at) }}</td>
						<td>
							<form method="POST" action="{{ url('panel/admin/replies/delete', $comment->id) }}" class="displayInline">
								@csrf
								<button type="submit" class="btn btn-danger rounded-pill btn-sm actionDelete">
									<i class="bi-trash-fill"></i>
								</button>
							</form>

								</td>

					</tr><!-- /.TR -->
                   @endforeach

						@else
							<h5 class="text-center p-5 text-muted fw-light m-0">{{ __('general.no_results_found') }}</h5>
						@endif

					</tbody>
					</table>
				</div><!-- /.box-body -->

				</div><!-- card-body -->
		</div><!-- card  -->

        @if ($data->lastPage() > 1)
			{{ $data->appends(['q' => request('q')])->onEachSide(0)->links() }}
		@endif

	</div><!-- col-lg-12 -->
</div><!-- end row -->
</div><!-- end content -->
@endsection
