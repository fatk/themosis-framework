import TextField from "./TextField";

/**
 * Native date field component.
 */
class DateField extends TextField {
    protected get inputType(): string {
        return 'date';
    }
}

export default DateField;
