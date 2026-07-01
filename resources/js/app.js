

import Alpine from 'alpinejs';

/**
 * Shared tick-box bulk-select for any list table.
 * Usage: <div x-data="bulkSelect(@js($ids))"> ... </div>
 * - checkbox in header:  @change="toggleAll($event.target.checked)" :checked="allChecked()"
 * - checkbox per row:    :value="{{ $row->id }}" x-model.number="selected"
 * - bar visible when:    x-show="selected.length"
 * - a hidden form with x-ref="bulkForm" + <input x-ref="bulkAction" name="action">
 *   and <template x-for="id in selected"><input name="ids[]" :value="id"></template>
 * - buttons:             @click="run('delete', { confirm: 'Delete %d item(s)?' })"
 */
window.bulkSelect = (pageIds = []) => ({
    selected: [],
    groupId: '',
    pageIds,
    allChecked() {
        return this.pageIds.length > 0 && this.pageIds.every((id) => this.selected.includes(id));
    },
    toggleAll(checked) {
        this.selected = checked ? [...this.pageIds] : [];
    },
    clear() {
        this.selected = [];
    },
    run(action, opts = {}) {
        if (opts.needGroup && ! this.groupId) {
            alert('Pick a group first.');
            return;
        }
        if (opts.confirm && ! confirm(opts.confirm.replace('%d', this.selected.length))) {
            return;
        }
        this.$refs.bulkAction.value = action;
        this.$refs.bulkForm.submit();
    },
});

window.Alpine = Alpine;

Alpine.start();
