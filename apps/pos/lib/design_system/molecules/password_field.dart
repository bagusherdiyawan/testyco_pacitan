import 'package:flutter/material.dart';

class PasswordField extends StatefulWidget {
  const PasswordField({
    required this.label,
    required this.controller,
    this.enabled = true,
    this.autofillHints,
    this.validator,
    this.onSubmitted,
    this.action = TextInputAction.next,
    super.key,
  });
  final String label;
  final TextEditingController controller;
  final bool enabled;
  final Iterable<String>? autofillHints;
  final FormFieldValidator<String>? validator;
  final ValueChanged<String>? onSubmitted;
  final TextInputAction action;
  @override
  State<PasswordField> createState() => _PasswordFieldState();
}

class _PasswordFieldState extends State<PasswordField> {
  bool hidden = true;
  @override
  Widget build(BuildContext context) => TextFormField(
    controller: widget.controller,
    enabled: widget.enabled,
    obscureText: hidden,
    autocorrect: false,
    enableSuggestions: false,
    keyboardType: TextInputType.visiblePassword,
    textInputAction: widget.action,
    autofillHints: widget.autofillHints,
    validator: widget.validator,
    onFieldSubmitted: widget.onSubmitted,
    decoration: InputDecoration(
      labelText: widget.label,
      suffixIcon: IconButton(
        tooltip:
            '${hidden ? 'Tampilkan' : 'Sembunyikan'} ${widget.label.toLowerCase()}',
        onPressed: widget.enabled
            ? () => setState(() => hidden = !hidden)
            : null,
        icon: Icon(hidden ? Icons.visibility : Icons.visibility_off),
      ),
    ),
  );
}
