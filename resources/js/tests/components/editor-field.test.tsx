import * as React from "react";
import EditorField from "../../src/components/fields/EditorField";

function fixture() {
    const field = {
        name: 'th_editor', value: '', attributes: {id: 'th_editor'},
        label: {inner: 'Editor'}, options: {settings_js: {}, info: ''},
        validation: {errors: false, rules: [], messages: []},
    } as unknown as FieldType;
    let serialized = '';
    const component = new EditorField({field, changeHandler: (_name: string, value: string) => { serialized = value; }});
    const textarea = (component.render().props.children[1].props.children as React.ReactElement[])[0];
    const events: {[name: string]: () => void} = {};
    let content = '';
    const editor = {on: (names: string, callback: () => void) => {
        names.split(' ').forEach(name => { events[name] = callback; });
    }, getContent: () => content};
    (global as any).wp = {oldEditor: {initialize: (_id: string, settings: any) => settings.tinymce.init_instance_callback(editor)}};
    (global as any).tinyMCE = {get: () => editor};
    component.componentDidMount();
    return {
        code: (value: string) => textarea.props.onChange?.({currentTarget: {value}}),
        visual: (value: string, event: string) => { content = value; events[event]?.(); },
        save: () => serialized,
    };
}

afterEach(() => {
    delete (global as any).wp;
    delete (global as any).tinyMCE;
});

test('Code edits are serialized before immediate save without blur', () => {
    const editor = fixture();
    editor.code('<p>code</p>');
    expect(editor.save()).toBe('<p>code</p>');
});

test.each(['input', 'change', 'blur'])('Visual %s synchronizes serialized content', event => {
    const editor = fixture();
    editor.visual('<p>visual</p>', event);
    expect(editor.save()).toBe('<p>visual</p>');
});

test('Visual and Code transitions and repeated saves retain the latest edit', () => {
    const editor = fixture();
    editor.visual('<p>first</p>', 'input');
    expect(editor.save()).toBe('<p>first</p>');
    editor.code('<p>second</p>');
    expect(editor.save()).toBe('<p>second</p>');
    expect(editor.save()).toBe('<p>second</p>');
    editor.visual('<p>third</p>', 'change');
    expect(editor.save()).toBe('<p>third</p>');
});
