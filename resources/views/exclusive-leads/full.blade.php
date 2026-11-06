{{-- One list (Pending or Catered) of the selected day in full, shown in the tracker's expand pop-up. --}}
<x-layouts.bare :title="$show === 'processed' ? 'Catered' : 'Pending'">
    @php($lockAssignee = true)
    @include('segmentation._section', $show === 'processed'
        ? ['id' => 'processed', 'title' => 'Catered', 'hint' => 'Status or contact date set, or marked as catered. Right-click a row to unmark it.', 'empty' => 'No catered customers for this day yet.']
        : ['id' => 'unprocessed', 'title' => 'Pending', 'hint' => 'No status or contact date yet: pick a customer and reach out. Right-click a row to mark it catered.', 'empty' => 'Nothing pending for this day.'])

    @include('segmentation._note-dialog')
    @include('segmentation._recording-dialog')

    <div data-x-scrollbar hidden class="x-scrollbar fixed bottom-0 z-30 overflow-x-auto overflow-y-hidden border-t border-line bg-white">
        <div data-x-scrollbar-width class="h-px"></div>
    </div>
</x-layouts.bare>
