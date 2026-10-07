{{-- One list (Unprocessed or Processed) in full, shown in the tracker's expand pop-up. --}}
<x-layouts.bare :title="$show === 'processed' ? 'Processed' : 'Unprocessed'">
    @php($canTransfer = false)

    @include('segmentation._section', $show === 'processed'
        ? ['id' => 'processed', 'title' => 'Processed', 'dot' => 'bg-teal', 'hint' => 'Status or contact date set.', 'empty' => 'No processed customers for this filter yet.']
        : ['id' => 'unprocessed', 'title' => 'Unprocessed', 'dot' => 'bg-coral', 'hint' => 'No status and no contact date yet: pick a customer and process them.', 'empty' => 'Nothing left to process for this filter.'])

    @include('segmentation._note-dialog')
</x-layouts.bare>
