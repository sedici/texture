/**
 * @file plugins/generic/texture/resources/js/main.js
 *
 * Copyright (c) 2021-2025 TIB Hannover
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_generic_texture
 *
 * @brief Vite main file: adds "Edit with Texture" to the XML files of the workflow file managers.
 *
 * As in OJS 3.4, the action is offered for XML files in the file lists of every workflow stage
 * (the XML converted by docxConverter lives in the copyediting stage). The workflow stage of the
 * file list is sent to the editor, which checks access against it.
 */

const FILE_MANAGER_NAMESPACES = [
	'SUBMISSION_FILES',
	'EDITOR_REVIEW_FILES',
	'WORKFLOW_REVIEW_REVISIONS',
	'FINAL_DRAFT_FILES',
	'COPYEDITED_FILES',
	'PRODUCTION_READY_FILES',
];

const XML_MIMETYPES = ['text/xml', 'application/xml'];

FILE_MANAGER_NAMESPACES.forEach((namespace) => {
	pkp.registry.storeExtend(`fileManager_${namespace}`, (piniaContext) => {
		const fileStore = piniaContext.store;

		const {useLocalize} = pkp.modules.useLocalize;
		const {useUrl} = pkp.modules.useUrl;
		const {t, localize} = useLocalize();
		const {pageUrl} = useUrl('texture');

		const isXml = (file) =>
			XML_MIMETYPES.includes(String(file.mimetype || '').toLowerCase()) ||
			String(localize(file.name) || '').toLowerCase().endsWith('.xml');

		fileStore.extender.extendFn('getItemActions', (itemActions, args) => {
			if (!args?.file || !isXml(args.file)) {
				return itemActions;
			}
			return [
				...itemActions,
				{
					label: t('plugins.generic.texture.links.editWithTexture'),
					name: 'editWithTexture',
					icon: 'FileText',
					actionFn: ({file}) => {
						const stageId = fileStore.props.submissionStageId;
						window.open(
							`${pageUrl.value}/editor?submissionId=${file.submissionId}&submissionFileId=${file.id}&stageId=${stageId}`,
						);
					},
				},
			];
		});
	});
});
