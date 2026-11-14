#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Unit tests for the literal checks in validate_example_set.py.

Run: python3 -I -m unittest discover -s tests/example-sets -p 'test_*.py'
"""
import glob
import importlib.util
import json
import os
import unittest

_HERE = os.path.dirname(os.path.abspath(__file__))
_SPEC = importlib.util.spec_from_file_location('validate_example_set', os.path.join(_HERE, 'validate_example_set.py'))
validator = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(validator)


def problems_for(data, path='data'):
    problems = []
    validator.literal_identifiers(data, path, problems, 'object "x"')
    return problems


class EmailDomainTest(unittest.TestCase):

    def test_the_set_domain_is_accepted(self):
        self.assertEqual([], problems_for({'email': 'info.slagerij@zuiddrecht.example'}))

    def test_any_example_domain_is_accepted(self):
        self.assertEqual([], problems_for({
            'email': 'info@bakkerijdegraaf.example',
            'reporterEmail': 'm.elamrani@ziggo.example',
        }))

    def test_a_subdomain_of_example_is_accepted(self):
        self.assertEqual([], problems_for({'handle': 'zuiddrecht@social.overheid.example'}))

    def test_an_address_in_running_text_is_accepted(self):
        self.assertEqual([], problems_for({'notes': 'Mail naar balie@sportcentrum.example.'}))

    def test_a_real_domain_is_refused(self):
        problems = problems_for({'email': 'jan@gmail.com'})
        self.assertEqual(1, len(problems))
        self.assertIn('jan@gmail.com', problems[0])

    def test_a_real_domain_that_merely_contains_example_is_refused(self):
        self.assertEqual(1, len(problems_for({'email': 'jan@zuiddrecht.example.com'})))
        self.assertEqual(1, len(problems_for({'email': 'jan@notexample'})))

    def test_an_address_inside_a_placeholder_is_not_checked(self):
        self.assertEqual([], problems_for({'email': '{{cast:henk.email}}'}))

    def test_nested_values_are_checked(self):
        problems = problems_for({'contacts': [{'email': 'a@b.nl'}]})
        self.assertEqual(1, len(problems))
        self.assertIn('data.contacts[0].email', problems[0])


class LiteralIdentifierTest(unittest.TestCase):

    def test_a_literal_kvk_number_is_refused(self):
        self.assertEqual(1, len(problems_for({'kvkNumber': '00001001'})))

    def test_a_cast_kvk_number_is_accepted(self):
        self.assertEqual([], problems_for({'kvkNumber': '{{cast:slagerij.kvk}}'}))


class UserFormatTest(unittest.TestCase):

    SCHEMA = {'type': 'array', 'items': {'type': 'string', 'format': 'user'}}

    def setUp(self):
        validator.CAST_UIDS.clear()
        validator.CAST_UIDS.update({'pieter', 'lotte.mulder'})

    def errors(self, value):
        check = validator.jsonschema.Draft202012Validator(self.SCHEMA, format_checker=validator.checker)
        return [e.message for e in check.iter_errors(value)]

    def test_a_cast_uid_is_accepted(self):
        self.assertEqual([], self.errors(['pieter', 'lotte.mulder']))

    def test_a_display_name_is_refused(self):
        self.assertEqual(["'Pieter Jansen' is not a 'user'"], self.errors(['Pieter Jansen']))

    def test_an_account_outside_the_cast_is_refused(self):
        self.assertEqual(1, len(self.errors(['admin'])))


class LookupTest(unittest.TestCase):

    DECLARED = [
        ('pipelinq', 'pipeline', {'title': 'Verkoop', 'isDefault': False}),
        ('pipelinq', 'pipeline', {'title': 'Vragen en meldingen', 'isDefault': True}),
    ]

    def check(self, where, lookup=None, register='pipelinq'):
        problems, warnings = [], []
        lookup = lookup or {'schema': 'pipeline', 'where': where}
        validator.lookup_problem('object "x"', 'pipeline', lookup, where, register, ['pipelinq'], self.DECLARED, problems, warnings)
        return problems, warnings

    def test_a_lookup_that_finds_one_declared_row_passes(self):
        self.assertEqual(([], []), self.check({'title': 'Verkoop'}))

    def test_a_lookup_that_finds_nothing_in_the_own_register_is_a_problem(self):
        problems, _ = self.check({'title': 'Sales', 'isDefault': True})
        self.assertEqual(1, len(problems))
        self.assertIn('finds no pipeline', problems[0])

    def test_a_lookup_that_finds_two_rows_is_a_problem(self):
        declared =self.DECLARED + [('pipelinq', 'pipeline', {'title': 'Verkoop', 'isDefault': True})]
        problems, warnings = [], []
        validator.lookup_problem('x', 'p', {'schema': 'pipeline', 'where': {'title': 'Verkoop'}}, {'title': 'Verkoop'},
                                 'pipelinq', ['pipelinq'], declared, problems, warnings)
        self.assertIn('finds 2 pipeline rows', problems[0])

    def test_a_miss_in_another_register_is_only_a_warning(self):
        problems, warnings = self.check({'title': 'Zaak 1'}, lookup={'schema': 'case', 'register': 'procest', 'where': {'title': 'Zaak 1'}})
        self.assertEqual([], problems)
        self.assertEqual(1, len(warnings))


class NumberScaleTest(unittest.TestCase):

    PROPS = {'weight': {'type': 'number'}, 'price': {'type': 'number'}, 'code': {'type': 'string'},
             'size': {'type': 'object', 'properties': {'depth': {'type': 'number'}}}}

    def test_two_decimals_fit(self):
        self.assertEqual([], validator.finer_than_storage({'price': 15.25, 'weight': 3.2}, self.PROPS))

    def test_three_decimals_are_named(self):
        self.assertEqual(['weight'], validator.finer_than_storage({'weight': 0.005, 'price': 15.0}, self.PROPS))

    def test_nested_numbers_are_checked(self):
        self.assertEqual(['size.depth'], validator.finer_than_storage({'size': {'depth': 0.008}}, self.PROPS))

    def test_strings_and_integers_are_left_alone(self):
        self.assertEqual([], validator.finer_than_storage({'code': '0.005', 'price': 3}, self.PROPS))


class OptionalNullTest(unittest.TestCase):

    SCHEMA = {'type': 'object', 'required': ['name'], 'properties': {
        'name': {'type': 'string'}, 'verified': {'type': 'boolean'},
        'nested': {'type': 'object', 'properties': {'note': {'type': 'string'}}}}}

    def errors(self, data):
        js = validator.to_jsonschema(self.SCHEMA)
        return [e.message for e in validator.jsonschema.Draft202012Validator(js).iter_errors(data)]

    def test_an_optional_property_may_be_null(self):
        self.assertEqual([], self.errors({'name': 'x', 'verified': None}))

    def test_a_nested_optional_property_may_be_null(self):
        self.assertEqual([], self.errors({'name': 'x', 'nested': {'note': None}}))

    def test_a_required_property_may_not_be_null(self):
        self.assertEqual(1, len(self.errors({'name': None})))

    def test_a_wrong_type_is_still_refused(self):
        self.assertEqual(1, len(self.errors({'name': 'x', 'verified': 'ja'})))


class OverriddenDeclarationTest(unittest.TestCase):

    TICKET = {'key': 't', 'register': 'pipelinq', 'schema': 'ticket', 'data': {'slaDeadline': '{{datetime:2 17:00}}', 'status': 'new'}}

    def change(self, sets, where=None):
        return {'key': 'c', 'register': 'pipelinq', 'schema': 'ticket', 'where': where or {'id': '{{object:t.id}}'}, 'set': sets}

    def problems(self, change):
        return validator.overridden_declarations({'objects': [self.TICKET], 'changes': [change]})

    def test_a_declared_field_set_to_another_value_is_refused(self):
        self.assertEqual(1, len(self.problems(self.change({'slaDeadline': '{{datetime:-1 17:00}}'}))))

    def test_a_field_the_object_does_not_declare_may_be_set(self):
        self.assertEqual([], self.problems(self.change({'assignee': '{{cast:pieter.uid}}'})))

    def test_a_change_found_by_another_field_is_left_alone(self):
        self.assertEqual([], self.problems(self.change({'slaDeadline': 'x'}, where={'title': 'T'})))

    def test_a_change_naming_the_object_by_key_is_refused(self):
        change = {'key': 'c', 'register': 'pipelinq', 'schema': 'ticket', 'object': 't', 'set': {'slaDeadline': 'x'}}
        self.assertEqual(1, len(self.problems(change)))

    def test_a_change_finding_the_object_by_a_declared_value_is_refused(self):
        self.assertEqual(1, len(self.problems(self.change({'slaDeadline': 'x'}, where={'status': 'new'}))))

    def test_a_change_finding_another_value_is_left_alone(self):
        self.assertEqual([], self.problems(self.change({'slaDeadline': 'x'}, where={'status': 'closed'})))


class ShippedSetTest(unittest.TestCase):

    def test_no_change_sets_a_field_its_object_declares(self):
        base = os.path.join(_HERE, '..', '..', 'lib', 'Settings', 'example-sets')
        fragment = {'objects': [], 'changes': []}
        for path in [os.path.join(base, 'zuiddrecht.json')] + sorted(glob.glob(os.path.join(base, 'zuiddrecht.d', '*.json'))):
            with open(path, encoding='utf-8') as handle:
                part = json.load(handle)
            for section in fragment:
                fragment[section] += part.get(section, [])
        self.assertEqual([], validator.overridden_declarations(fragment))


if __name__ == '__main__':
    unittest.main()
