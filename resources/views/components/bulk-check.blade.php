@props(['id' => null])

{{-- Row tick-box (omit :id for the select-all header box). --}}
@if (is_null($id))
    <input type="checkbox" @change="toggleAll($event.target.checked)" :checked="allChecked()"
           class="rounded border-gray-300 text-brand focus:ring-brand">
@else
    <input type="checkbox" :value="{{ $id }}" x-model.number="selected"
           class="rounded border-gray-300 text-brand focus:ring-brand">
@endif
