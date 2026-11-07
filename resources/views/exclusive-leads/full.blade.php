{{-- One list (Pending, with the follow-up tabs, or Catered) in full, shown in the tracker's expand pop-up, as in the Segmentation Tracker. --}}
<x-layouts.bare :title="$show === 'processed' ? 'Catered' : 'Pending'">
    @php($lockAssignee = true)
    @if ($show === 'unprocessed')
        @include('segmentation._pending-lists', ['pendingEmpty' => 'Nothing pending for this filter.'])
    @else
        @include('segmentation._section', ['id' => 'processed', 'title' => 'Catered', 'hint' => 'Status or contact date set, or marked as catered. Right-click a row to unmark it.', 'empty' => 'No catered customers for this filter yet.'])
    @endif

    @include('segmentation._note-dialog')
    @include('segmentation._recording-dialog')

    <div data-x-scrollbar hidden class="x-scrollbar fixed bottom-0 z-30 overflow-x-auto overflow-y-hidden border-t border-line bg-white">
        <div data-x-scrollbar-width class="h-px"></div>
    </div>
</x-layouts.bare>
