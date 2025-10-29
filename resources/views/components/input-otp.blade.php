@props([
    'digits' => 6,
    'name' => 'code',
])

<div
    @focus-2fa-auth-code.window="$refs.input1?.focus()"
    @clear-2fa-auth-code.window="clearAll()"
    class="relative"
    x-data="{
        totalDigits: @js($digits),
        digitIndices: @js(range(1, $digits)),
        init() {
            $nextTick(() => {
                this.$refs.input1?.focus();
            });
        },
        getInput(index) {
            return this.$refs['input' + index];
        },
        setValue(index, value) {
            this.getInput(index).value = value;
        },
        getCode() {
            return this.digitIndices
                .map(i => this.getInput(i).value)
                .join('');
        },
        updateHiddenField() {
            this.$refs.code.value = this.getCode();
            this.$refs.code.dispatchEvent(new Event('input', { bubbles: true }));
            this.$refs.code.dispatchEvent(new Event('change', { bubbles: true }));
        },
        handleNumberKey(index, key) {
            this.setValue(index, key);

            if (index < this.totalDigits) {
                this.getInput(index + 1).focus();
            }

            $nextTick(() => {
                this.updateHiddenField();
            });
        },
        handleBackspace(index) {
            const currentInput = this.getInput(index);

            if (currentInput.value !== '') {
                currentInput.value = '';
                this.updateHiddenField();
                return;
            }

            if (index <= 1) {
                return;
            }

            const previousInput = this.getInput(index - 1);
    
            previousInput.value = '';
            previousInput.focus();

            this.updateHiddenField();
        },
        handleKeyDown(index, event) {
            const key = event.key;

            if (/^[0-9]$/.test(key)) {
                event.preventDefault();
                this.handleNumberKey(index, key);
                return;
            }

            if (key === 'Backspace') {
                event.preventDefault();
                this.handleBackspace(index);
                return;
            }
        },
        handlePaste(event) {
            event.preventDefault();

            const pastedText = (event.clipboardData || window.clipboardData).getData('text');
            const numericOnly = pastedText.replace(/[^0-9]/g, '');
            const digitsToFill = Math.min(numericOnly.length, this.totalDigits);

            this.digitIndices
                .slice(0, digitsToFill)
                .forEach(index => {
                    this.setValue(index, numericOnly[index - 1]);
                });

            if (numericOnly.length >= this.totalDigits) {
                this.updateHiddenField();
            }
        },
        clearAll() {
            this.digitIndices.forEach(index => {
                this.setValue(index, '');
            });

            this.$refs.code.value = '';
            this.$refs.input1?.focus();
        }
    }"
>
    <div class="flex items-center">
        @for ($x = 1; $x <= $digits; $x++)
            <input
                x-ref="input{{ $x }}"
                type="text"
                inputmode="numeric"
                pattern="[0-9]"
                maxlength="1"
                autocomplete="off"
                @paste="handlePaste"
                @keydown="handleKeyDown({{ $x }}, $event)"
                @focus="$el.select()"
                @input="$el.value = $el.value.replace(/[^0-9]/g, '').slice(0, 1)"
                @class([
                    'flex size-10 items-center justify-center border border-zinc-300 bg-accent-foreground text-center text-sm font-medium text-accent-content transition-colors focus:border-accent focus:border-2 focus:outline-none focus:relative focus:z-10 dark:border-zinc-700 dark:focus:border-accent',
                    'rounded-l-md' => $x === 1,
                    'rounded-r-md' => $x === $digits,
                    '-ml-px' => $x > 1,
                ])
            />
        @endfor
    </div>

    <input
        {{ $attributes->except(['digits']) }}
        type="hidden"
        x-ref="code"
        name="{{ $name }}"
        minlength="{{ $digits }}"
        maxlength="{{ $digits }}"
    />
</div>
