@foreach($fields as $field)
    <div class="form-field {{ 'term-'.$field->getBaseName().'-wrap' }}">
        <label {!! $field->attributes($field->getOption('label_attr')) !!}>{{ $field->getOption('label') }}</label>
        <input type="hidden" name="_themosis_fields[{{ $field->getName() }}]" value="1">
        {!! $field->render() !!}
    </div>
@endforeach