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

function registerDateComponent() {
    const originalWindow = Object.getOwnPropertyDescriptor(global, 'window');
    const headers = require('axios').default.defaults.headers.common;
    const originalHeader = Object.getOwnPropertyDescriptor(headers, 'Themosis-Api-Request');
    Object.defineProperty(global, 'window', {value: {addEventListener: jest.fn()}, configurable: true});
    try {
        const core = require('../../index').default;
        expect(core.components.get('themosis.fields.date')).toBe(require('../../src/components/fields/DateField').default);
    } finally {
        if (originalWindow) Object.defineProperty(global, 'window', originalWindow);
        else delete (global as any).window;
        if (originalHeader) Object.defineProperty(headers, 'Themosis-Api-Request', originalHeader);
        else delete headers['Themosis-Api-Request'];
    }
}

test.each([undefined, 'existing-request'])('Public registration preserves existing browser and Axios state (%s)', header => {
    jest.resetModules();
    const originalWindow = Object.getOwnPropertyDescriptor(global, 'window');
    const headers = require('axios').default.defaults.headers.common;
    const originalHeader = Object.getOwnPropertyDescriptor(headers, 'Themosis-Api-Request');
    const existingWindow = {addEventListener: jest.fn(), marker: 'existing-browser'};
    const descriptor = {value: existingWindow, configurable: true, writable: true, enumerable: false};
    Object.defineProperty(global, 'window', descriptor);
    if (header === undefined) {
        delete headers['Themosis-Api-Request'];
    } else {
        headers['Themosis-Api-Request'] = header;
    }
    try {
        registerDateComponent();
        expect({
            window: Object.getOwnPropertyDescriptor(global, 'window'),
            headerPresent: Object.prototype.hasOwnProperty.call(headers, 'Themosis-Api-Request'),
            headerValue: headers['Themosis-Api-Request'],
        }).toEqual({window: descriptor, headerPresent: header !== undefined, headerValue: header});
    } finally {
        if (originalWindow) Object.defineProperty(global, 'window', originalWindow);
        else delete (global as any).window;
        if (originalHeader) Object.defineProperty(headers, 'Themosis-Api-Request', originalHeader);
        else delete headers['Themosis-Api-Request'];
    }
});
