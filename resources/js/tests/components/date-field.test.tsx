import * as React from "react";
import DateField from "../../src/components/fields/DateField";
import TextField from "../../src/components/fields/TextField";

function field(value: string): FieldType {
    return {
        name: 'th_birthday', value, attributes: {id: 'th_birthday', min: '2020-01-01'},
        label: {inner: 'Birthday'}, options: {info: ''},
        validation: {errors: false, rules: [], messages: []},
    } as unknown as FieldType;
}

test.each(['2026-10-12', ''])('Native date renders and serializes %s', value => {
    const changeHandler = jest.fn();
    const component = new DateField({field: field(value), changeHandler});
    const input = (component.render().props.children[1].props.children as React.ReactElement[])[0];
    expect(input.props.type).toBe('date');
    expect(input.props.name).toBe('th_birthday');
    expect(input.props.value).toBe(value);
    expect(input.props.min).toBe('2020-01-01');
    input.props.onChange({target: {value}});
    expect(changeHandler).toHaveBeenCalledWith('th_birthday', value);
    const text = new TextField({field: field(value), changeHandler});
    expect(text.render().props.children[1].props.children[0].props.type).toBe('text');
});

jest.mock('../../src/components/MetaboxFactory', () => ({__esModule: true, default: () => null}));
jest.mock('@wordpress/components', () => ({}));
jest.mock('../../src/components/fields/CollectionField', () => ({__esModule: true, default: () => null}));

test('Public core registers the date component', () => {
    (global as any).window = {addEventListener: jest.fn()};
    try {
        const core = require('../../index').default;
        expect(core.components.get('themosis.fields.date')).toBe(DateField);
    } finally {
        delete (global as any).window;
    }
});
