@extends('admin.layout')

@section('content')
<h5 class="mb-4 fw-light">
  <a class="text-reset" href="{{ url('panel/admin') }}">{{ __('admin.dashboard') }}</a>
  <i class="bi-chevron-right me-1 fs-6"></i>
  <span class="text-muted">{{ __('general.messages') }} ({{$data->total()}})</span>
</h5>

<div class="content">
  <div class="row">
    <div class="col-lg-12">
      @if (session('success'))
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check2 me-1"></i> {{ session('success') }}

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
							action="{{ url('panel/admin/messages') }}" method="get">
							<i class="bi bi-search btn-search bar-search"></i>
							<input type="text" name="q" class="form-control ps-5 w-auto" value="" placeholder="{{ __('general.search') }}">
						</form><!-- form -->
					</div>

          <div class="table-responsive p-0">
            <table class="table table-hover">
              <tbody>

                @if ($data->count() != 0)
                <tr>
                  <th class="active">{{ __('general.sender') }}</th>
                  <th class="active">{{ __('general.receiver') }}</th>
                  <th class="active">{{ __('general.message') }}</th>
                  <th class="active">{{ __('admin.date') }}</th>
                </tr>

                @foreach ($data as $message)
                <tr>
                  <td>
                    @if ($message->sender)
                      <a href="{{ url($message->sender->username) }}" target="_blank">
                        {{ $message->sender->username }} <i class="fa fa-external-link-square-alt"></i>
                      </a>
                      @else
                      {{ __('general.no_available') }}
                    @endif
                  </td>

                  <td>
                    @if ($message->receiver)
                      <a href="{{ url($message->receiver->username) }}" target="_blank">
                        {{ $message->receiver->username }} <i class="fa fa-external-link-square-alt"></i>
                      </a>
                      @else
                    {{ __('general.no_available') }}
                    @endif
                  </td>
                  <td>
                    {{ $message->message ?: __('-') }}
                  </td>
                  <td>{{ Helper::formatDate($message->created_at) }}</td>

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