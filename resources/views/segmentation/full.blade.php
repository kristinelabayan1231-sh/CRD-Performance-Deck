{{-- One list (Unprocessed or Processed) in full, shown in the tracker's expand pop-up. --}}
<x-layouts.bare :title="$show === 'processed' ? 'Catered' : 'Pending'">
    @php($canTransfer = false)

    @include('segmentation._section', $show === 'processed'
        ? ['id' => 'processed', 'title' => 'Catered', 'dot' => 'bg-teal', 'hint' => 'Status or contact date set, or marked as catered. Right-click a row to unmark it.', 'empty' => 'No catered customers for this filter yet.']
        : ['id' => 'unprocessed', 'title' => 'Pending', 'dot' => 'bg-coral', 'hint' => 'No status or contact date yet: pick a customer and reach out. Right-click a row to mark it catered.', 'empty' => 'Nothing pending for this filter.'])

    @include('segmentation._note-dialog')
</x-layouts.bare>
