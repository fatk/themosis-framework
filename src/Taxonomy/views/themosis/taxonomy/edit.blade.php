@foreach($fields as $field)
    <tr class="form-field {{ 'term-'.$field->getBaseName().'-wrap' }}">
        <th scope="row">
            <label {!! $field->attributes($field->getOption('label_attr')) !!}>{{ $field->getOption('label') }}</label>
        </th>
        <td>
            <input type="hidden" name="_themosis_fields[{{ $field->getName() }}]" value="1">
            {!! $field->render() !!}
        </td>
    </tr>
@endforeach